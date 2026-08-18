<?php
// This file is part of Moodle - https://moodle.org/.
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace enrol_course_tokens\local;

/**
 * Authoritative token lifecycle decisions and mutations.
 *
 * A Moodle enrolment row can be reused by several renewal cycles and can later
 * disappear. The permanent consumption facts therefore live on the token.
 *
 * @package enrol_course_tokens
 * @copyright 2026 Pacific Medical Training
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class lifecycle_service {
    /** Shared lock namespace for every lifecycle mutation. */
    public const LOCK_NAMESPACE = 'enrol_course_tokens_lifecycle';

    /** @var int Lock wait in seconds. */
    private const LOCK_TIMEOUT = 5;

    /** @var array<string, \stdClass|null> Per-request latest-token lookup cache. */
    private static $latestconsumedcache = [];

    /**
     * Log diagnostics server-side without exposing exception details in JSON.
     *
     * @param string $message Diagnostic message.
     * @return void
     */
    private static function log_error(string $message): void {
        // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative -- debugging() can be browser-visible.
        error_log($message);
    }

    /**
     * Whether a token has ever been consumed.
     *
     * @param \stdClass $token Token record.
     * @return bool
     */
    public static function is_consumed(\stdClass $token): bool {
        return property_exists($token, 'used_on') && $token->used_on !== null;
    }

    /**
     * Whether a token is clean, redeemable inventory.
     *
     * @param \stdClass $token Token record.
     * @return bool
     */
    public static function is_available(\stdClass $token): bool {
        return property_exists($token, 'used_on') && $token->used_on === null
            && property_exists($token, 'used_by_user_id') && $token->used_by_user_id === null
            && property_exists($token, 'user_enrolments_id') && $token->user_enrolments_id === null
            && isset($token->voided) && (int) $token->voided === 0
            && property_exists($token, 'voided_at') && $token->voided_at === null;
    }

    /**
     * Apply permanent consumption facts to a token record.
     *
     * @param \stdClass $token Token record to update.
     * @param int $userenrolmentid Current Moodle enrolment row.
     * @param int $learnerid Actual learner, never the purchaser by assumption.
     * @param int $usedon Consumption timestamp.
     * @return void
     */
    public static function set_consumed_fields(
        \stdClass $token,
        int $userenrolmentid,
        int $learnerid,
        int $usedon
    ): void {
        $token->user_enrolments_id = $userenrolmentid;
        $token->used_by_user_id = $learnerid;
        $token->used_on = $usedon;
        $token->timemodified = $usedon;

        if (property_exists($token, 'course_id')) {
            self::clear_latest_consumed_cache($learnerid, (int) $token->course_id);
        }
    }

    /**
     * Resolve the permanent learner, with a legacy live-enrolment fallback.
     *
     * @param \stdClass $token Token record.
     * @return int|null
     */
    public static function get_learner_id(\stdClass $token): ?int {
        global $DB;

        if (property_exists($token, 'used_by_user_id') && $token->used_by_user_id !== null) {
            return (int) $token->used_by_user_id;
        }
        if (property_exists($token, 'user_enrolments_id') && $token->user_enrolments_id !== null) {
            $userid = $DB->get_field('user_enrolments', 'userid', ['id' => $token->user_enrolments_id]);
            return $userid === false ? null : (int) $userid;
        }
        return null;
    }

    /**
     * Return the latest consumed token for a learner and course.
     *
     * @param int $userid Learner ID.
     * @param int $courseid Course ID.
     * @return \stdClass|null
     */
    public static function get_latest_consumed_token(int $userid, int $courseid): ?\stdClass {
        global $DB;

        $cachekey = $userid . ':' . $courseid;
        if (array_key_exists($cachekey, self::$latestconsumedcache)) {
            return self::$latestconsumedcache[$cachekey];
        }

        $records = $DB->get_records_sql(
            "SELECT *
               FROM {course_tokens}
              WHERE used_by_user_id = :userid
                AND course_id = :courseid
                AND used_on IS NOT NULL
           ORDER BY used_on DESC, id DESC",
            ['userid' => $userid, 'courseid' => $courseid],
            0,
            1
        );
        self::$latestconsumedcache[$cachekey] = $records ? reset($records) : null;
        return self::$latestconsumedcache[$cachekey];
    }

    /**
     * Invalidate a per-request latest-token cache entry after lifecycle mutation.
     *
     * @param int $userid Learner ID.
     * @param int $courseid Course ID.
     * @return void
     */
    private static function clear_latest_consumed_cache(int $userid, int $courseid): void {
        unset(self::$latestconsumedcache[$userid . ':' . $courseid]);
    }

    /**
     * Validate the complete unenrol/refund rule.
     *
     * @param \stdClass $token Token record.
     * @return \stdClass|null Valid live enrolment, or null when ineligible.
     */
    public static function get_refundable_enrolment(\stdClass $token): ?\stdClass {
        global $DB;

        if (
            !self::is_consumed($token)
                || !property_exists($token, 'used_by_user_id') || $token->used_by_user_id === null
                || (int) $token->used_by_user_id <= 0
                || !property_exists($token, 'user_enrolments_id') || $token->user_enrolments_id === null
                || (int) $token->user_enrolments_id <= 0
                || !property_exists($token, 'course_id') || (int) $token->course_id <= 0
                || !property_exists($token, 'voided') || (int) $token->voided !== 0
                || !property_exists($token, 'voided_at') || $token->voided_at !== null
        ) {
            return null;
        }

        $latest = self::get_latest_consumed_token((int) $token->used_by_user_id, (int) $token->course_id);
        if (!$latest || (int) $latest->id !== (int) $token->id) {
            return null;
        }

        $enrolment = $DB->get_record_sql(
            "SELECT ue.*, e.courseid, e.enrol AS enrolplugin
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.id = :ueid",
            ['ueid' => $token->user_enrolments_id]
        );
        if (
            !$enrolment
                || (int) $enrolment->userid !== (int) $token->used_by_user_id
                || (int) $enrolment->courseid !== (int) $token->course_id
        ) {
            return null;
        }
        return $enrolment;
    }

    /**
     * Whether this is the active cycle, including a valid matching live link.
     *
     * @param \stdClass $token Token record.
     * @return bool
     */
    public static function is_active_consumed_token(\stdClass $token): bool {
        return self::get_refundable_enrolment($token) !== null;
    }

    /**
     * Acquire token then learner/course locks in the global lifecycle order.
     *
     * @param int $tokenid Token ID.
     * @param int $userid Learner ID.
     * @param int $courseid Course ID.
     * @return array Locks in acquisition order.
     * @throws \RuntimeException If either lock cannot be acquired.
     */
    public static function acquire_locks(int $tokenid, int $userid, int $courseid): array {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_NAMESPACE);
        $locks = [];
        try {
            $locks[] = $factory->get_lock('token:' . $tokenid, self::LOCK_TIMEOUT);
            if (!$locks[0]) {
                throw new \RuntimeException('Could not acquire token lifecycle lock.');
            }
            $locks[] = $factory->get_lock(
                'user:' . $userid . ':course:' . $courseid,
                self::LOCK_TIMEOUT
            );
            if (!$locks[1]) {
                throw new \RuntimeException('Could not acquire learner/course lifecycle lock.');
            }
        } catch (\Throwable $e) {
            self::release_locks($locks);
            throw $e;
        }
        return $locks;
    }

    /**
     * Acquire the token-only prefix of the lifecycle lock order.
     *
     * @param int $tokenid Token ID.
     * @return mixed Moodle lock object.
     */
    public static function acquire_token_lock(int $tokenid) {
        $factory = \core\lock\lock_config::get_lock_factory(self::LOCK_NAMESPACE);
        $lock = $factory->get_lock('token:' . $tokenid, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new \RuntimeException('Could not acquire token lifecycle lock.');
        }
        return $lock;
    }

    /**
     * Release lifecycle locks in reverse order.
     *
     * @param array $locks Locks returned by acquire_locks().
     * @return void
     */
    public static function release_locks(array $locks): void {
        foreach (array_reverse($locks) as $lock) {
            if ($lock) {
                $lock->release();
            }
        }
    }

    /**
     * Safely unenrol and return only the selected latest token to inventory.
     *
     * @param int $tokenid Token ID.
     * @return bool
     */
    public static function refund_and_unenrol(int $tokenid): bool {
        global $DB;

        $initial = $DB->get_record('course_tokens', ['id' => $tokenid]);
        if (!$initial || $initial->used_by_user_id === null) {
            return false;
        }

        try {
            $locks = self::acquire_locks(
                (int) $initial->id,
                (int) $initial->used_by_user_id,
                (int) $initial->course_id
            );
        } catch (\Throwable $e) {
            self::log_error('enrol_course_tokens: unenrol lock failed for token ' . $tokenid . ': ' . $e->getMessage());
            return false;
        }
        $transaction = null;
        try {
            $token = $DB->get_record('course_tokens', ['id' => $tokenid], '*', MUST_EXIST);
            if (
                (int) $token->course_id !== (int) $initial->course_id
                    || $token->used_by_user_id === null
                    || (int) $token->used_by_user_id !== (int) $initial->used_by_user_id
            ) {
                return false;
            }
            $enrolment = self::get_refundable_enrolment($token);
            if (!$enrolment) {
                return false;
            }
            $plugin = enrol_get_plugin($enrolment->enrolplugin);
            if (!$plugin) {
                return false;
            }
            $instance = $DB->get_record('enrol', ['id' => $enrolment->enrolid], '*', MUST_EXIST);

            $sharedueid = (int) $token->user_enrolments_id;
            $now = time();
            $transaction = $DB->start_delegated_transaction();
            $plugin->unenrol_user($instance, (int) $token->used_by_user_id);
            if ($DB->record_exists('user_enrolments', ['id' => $sharedueid])) {
                throw new \RuntimeException('The Moodle enrolment was not removed.');
            }

            // Renewals may all point at the one row just removed. Only the selected
            // cycle becomes inventory; older cycles retain their permanent facts.
            $DB->execute(
                "UPDATE {course_tokens}
                    SET user_enrolments_id = NULL,
                        timemodified = :timemodified
                  WHERE user_enrolments_id = :ueid",
                ['timemodified' => $now, 'ueid' => $sharedueid]
            );
            $DB->execute(
                "UPDATE {course_tokens}
                    SET user_enrolments_id = NULL,
                        used_by_user_id = NULL,
                        used_on = NULL,
                        timemodified = :timemodified
                  WHERE id = :tokenid",
                ['timemodified' => $now, 'tokenid' => $tokenid]
            );

            self::clear_latest_consumed_cache(
                (int) $token->used_by_user_id,
                (int) $token->course_id
            );

            $refunded = $DB->get_record('course_tokens', ['id' => $tokenid], '*', MUST_EXIST);
            if (!self::is_available($refunded)) {
                throw new \RuntimeException('The refunded token did not return to clean inventory.');
            }
            if ($DB->record_exists('course_tokens', ['user_enrolments_id' => $sharedueid])) {
                throw new \RuntimeException('A stale shared enrolment reference remains after unenrolment.');
            }

            $transaction->allow_commit();
            $transaction = null;
            return true;
        } catch (\Throwable $e) {
            if ($transaction) {
                try {
                    $transaction->rollback($e);
                } catch (\Throwable $rollbackexception) {
                    self::log_error('enrol_course_tokens: unenrol rollback failed for token '
                        . $tokenid . ': ' . $rollbackexception->getMessage());
                }
            }
            self::log_error('enrol_course_tokens: unenrol failed for token ' . $tokenid . ': ' . $e->getMessage());
            return false;
        } finally {
            self::release_locks($locks);
        }
    }

    /**
     * Void a token, atomically refunding it first only when it is the active cycle.
     *
     * @param int $tokenid Token ID.
     * @param string $notes Administrative reason.
     * @return bool
     */
    public static function void_token(int $tokenid, string $notes): bool {
        global $DB;

        $initial = $DB->get_record('course_tokens', ['id' => $tokenid]);
        if (!$initial) {
            return false;
        }
        $learnerid = $initial->used_by_user_id === null ? null : (int) $initial->used_by_user_id;
        try {
            $locks = $learnerid === null
                ? [self::acquire_token_lock($tokenid)]
                : self::acquire_locks($tokenid, $learnerid, (int) $initial->course_id);
        } catch (\Throwable $e) {
            self::log_error('enrol_course_tokens: void lock failed for token ' . $tokenid . ': ' . $e->getMessage());
            return false;
        }
        $transaction = null;
        try {
            $token = $DB->get_record('course_tokens', ['id' => $tokenid], '*', MUST_EXIST);
            if (
                (int) $token->course_id !== (int) $initial->course_id
                    || (($token->used_by_user_id === null) !== ($learnerid === null))
                    || ($learnerid !== null && (int) $token->used_by_user_id !== $learnerid)
            ) {
                return false;
            }

            $transaction = $DB->start_delegated_transaction();
            $enrolment = self::get_refundable_enrolment($token);
            if ($enrolment) {
                $plugin = enrol_get_plugin($enrolment->enrolplugin);
                $instance = $DB->get_record('enrol', ['id' => $enrolment->enrolid], '*', MUST_EXIST);
                if (!$plugin) {
                    throw new \RuntimeException('Enrolment plugin is unavailable.');
                }
                $sharedueid = (int) $token->user_enrolments_id;
                $plugin->unenrol_user($instance, (int) $token->used_by_user_id);
                if ($DB->record_exists('user_enrolments', ['id' => $sharedueid])) {
                    throw new \RuntimeException('The Moodle enrolment was not removed.');
                }
                $DB->execute(
                    "UPDATE {course_tokens}
                        SET user_enrolments_id = NULL,
                            timemodified = :timemodified
                      WHERE user_enrolments_id = :ueid",
                    ['timemodified' => time(), 'ueid' => $sharedueid]
                );
                $token->user_enrolments_id = null;
                $token->used_by_user_id = null;
                $token->used_on = null;
            }

            $now = time();
            $token->voided = 1;
            $token->voided_at = $now;
            $token->voided_notes = $notes;
            $token->timemodified = $now;
            $DB->update_record('course_tokens', $token);
            if ($learnerid !== null) {
                self::clear_latest_consumed_cache($learnerid, (int) $token->course_id);
            }
            $transaction->allow_commit();
            $transaction = null;
            return true;
        } catch (\Throwable $e) {
            if ($transaction) {
                try {
                    $transaction->rollback($e);
                } catch (\Throwable $rollbackexception) {
                    self::log_error('enrol_course_tokens: void rollback failed for token '
                        . $tokenid . ': ' . $rollbackexception->getMessage());
                }
            }
            self::log_error('enrol_course_tokens: void failed for token ' . $tokenid . ': ' . $e->getMessage());
            return false;
        } finally {
            self::release_locks($locks);
        }
    }
}

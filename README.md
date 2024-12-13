# STATUS

This plugin is used in a preproduction installation.

Remaining issues before public 1.0.0 release are in [Issue #49](https://github.com/fulldecent/moodle-enrol_course_tokens/issues/49).

## What is it?

This plugin lets you make single-use tokens for courses. Then anybody can use these tokens to create an account on your Moodle instance (if they don\'t already have one) and enroll in the course.

## Installation

Install using `git`. Other ways may be possible but only `git` is supported.

Type this command in the root of your Moodle installation:

```sh
git clone git://github.com/fulldecent/moodle-enrol_course_tokens.git ./enrol/course_tokens
```

You may add this to your `gitignore` or local `exclude` files, e.g.:

```
echo '/enrol/course_tokens' >> .git/info/exclude
```

Log into your Moodle instance as *admin*: the installation process will start. Alternatively, visit the *Site administration > Notifications* page.

After you have installed this enrol plugin, you'll need to configure it under *Site administration -> Plugins -> Enrol plugins -> Twitter card* in the *Settings* block.

## Dashboard Block

**Purpose**
A dashboard block provides users with a summary of token statuses and allows them to manage their available tokens.

**Features**

- Displays token counts (e.g., available, assigned, in progress, completed, failed) for each course.
- Allows users to assign tokens directly from their inventory.

**Usage**

To enable the block:
1. Create a symlink for the block: `ln -s ../enrol/course_tokens/block/ course_tokens`
2. Add the block to the dashboard, or any desired location.

## Features / specification

* [ ] All text is internationalized and new languages can be added
* [ ] Site administrator can create tokens (/enrol/course_tokens/)
  * [x] Admin will select a course, enter a quantity
  * [x] Can specify arbitrary JSON to connect with this enrollment (e.g. group assignment, email opt-out)
  * [x] The token code is created automatically
    * [x] From the course ID number like cprfaaed-f7df-7781
    * [x] It can't be guessed
  * [ ] Admin can directly assign to a (new) student when creating token
* [ ] Activate page (/enrol/assign.php)
  * [ ] Buttons allow to add or remove tokens and do a bunch at a time (TODO: need to document development process, JavaScript is complicated with Moodle plugin development)
  * [ ] Token IDs validate before they are used

## Contributing notes

* [ ] TODO: add link to authoritative notes for setting up a test environment // maybe? https://github.com/moodlehq/moodle-docker // after fix issue https://github.com/moodlehq/moodle-docker/issues/287
* [ ] TODO: add link to authoritative notes for developming modules

When updating the lang/*/* files, be sure to run:

```sh
php admin/cli/purge_caches.php
```

## Project scope

Version 2 (i.e. won't do it, 100% perfect pull requests will be reviewed but not merged for a while)

\- more fine-grained permission control, more people can create tokens for some subset of courses

## See also

* A competing plugin, [Sebsoft Coupon Plugin]([Moodle plugins directory: Coupon | Moodle.org](https://moodle.org/plugins/block_coupon)), requires you to create student accounts before enrolling them

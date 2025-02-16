<?php
require_once('../../config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/enrol/course_tokens/index.php'));
$PAGE->set_title(get_string('pluginname', 'enrol_course_tokens'));
$PAGE->set_heading(get_string('pluginname', 'enrol_course_tokens'));

// Load from databases, order tokens by creation time (newer first)
$tokens = $DB->get_records('course_tokens', null, 'timecreated DESC');
$sql = "
    SELECT c.id, c.fullname
    FROM {course} c
    JOIN {enrol} e ON e.courseid = c.id
    WHERE e.enrol = 'course_tokens'
";
$courses = $DB->get_records_sql_menu($sql, []);

// Start output
echo $OUTPUT->header();
echo '<p>' . s(get_string('introduction', 'enrol_course_tokens')) . '</p>';

echo '<form id="createTokenForm" action="do-create-token.php" method="post">';
echo '<div class="form-item row mb-3">';
// Select a course
echo '<div class="form-label col-sm-3 text-sm-right">';
echo '<label for="course_id">' . s(get_string('coursename', 'enrol_course_tokens')) . '</label>';
echo '</div>';
echo '<div class="form-setting col-sm-9">';
echo html_writer::select($courses, 'course_id', '', get_string('coursename', 'enrol_course_tokens'));
echo '</div>';
echo '</div>';
// Email
echo '<div class="form-item row mb-3">';
echo '<div class="form-label col-sm-3 text-sm-right">';
echo '<label for="email">' . s(get_string('email', 'enrol_course_tokens')) . '</label>';
echo '</div>';
echo '<div class="form-setting col-sm-9">';
echo '<input type="email" class="form-control" id="email" name="email" required>';
echo '<div id="emailError" class="text-danger small mt-1"></div>';
echo '</div>';
echo '</div>';
// First Name
echo '<div class="form-item row mb-3">';
echo '<div class="form-label col-sm-3 text-sm-right">';
echo '<label for="firstname">' . s(get_string('firstname', 'enrol_course_tokens')) . '</label>';
echo '</div>';
echo '<div class="form-setting col-sm-9">';
echo '<input type="text" class="form-control" id="firstname" name="firstname" required>';
echo '</div>';
echo '</div>';
// Last Name
echo '<div class="form-item row mb-3">';
echo '<div class="form-label col-sm-3 text-sm-right">';
echo '<label for="lastname">' . s(get_string('lastname', 'enrol_course_tokens')) . '</label>';
echo '</div>';
echo '<div class="form-setting col-sm-9">';
echo '<input type="text" class="form-control" id="lastname" name="lastname" required>';
echo '</div>';
echo '</div>';
// Corporate Account (optional)
echo '<div class="form-item row mb-3">';
echo '<div class="form-label col-sm-3 text-sm-right">';
echo '<label for="group_account">' . s(get_string('corporateaccount', 'enrol_course_tokens')) . '</label>';
echo '</div>';
echo '<div class="form-setting col-sm-9">';
echo '<input type="text" class="form-control" id="group_account" name="group_account">';
echo '</div>';
echo '</div>';
// Extra JSON
echo '<div class="form-item row mb-3">';
echo '<div class="form-label col-sm-3 text-sm-right">';
echo '<label for="extra_json">' . s(get_string('extrajson', 'enrol_course_tokens')) . '</label>';
echo '</div>';
echo '<div class="form-setting col-sm-9">';
echo '<textarea class="form-control" id="extra_json" name="extra_json" placeholder="Enter the order number here like \'1004\'"></textarea>';
echo '</div>';
echo '</div>';
// Quantity
echo '<div class="form-item row mb-3">';
echo '<div class="form-label col-sm-3 text-sm-right">';
echo '<label for="quantity">' . s(get_string('quantity', 'enrol_course_tokens')) . '</label>';
echo '</div>';
echo '<div class="form-setting col-sm-9">';
echo '<input type="number" class="form-control" id="quantity" name="quantity" min="1" required>';
echo '</div>';
echo '</div>';
// Submit
echo '<div class="form-item row mb-3">';
echo '<div class="form-label col-sm-3 text-sm-right"></div>';
echo '<div class="form-setting col-sm-9">';
echo '<input type="submit" class="btn btn-primary" value="' . s(get_string('createtokens', 'enrol_course_tokens')) . '">';
echo '</div>';
echo '</div>';
// CSRF token
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '"/>';
echo '</form>';

// Show existing tokens
echo '<h2 class="my-3">' . s(get_string('existingtokens', 'enrol_course_tokens')) . '</h2>';
echo '<table class="table">';
echo '<tr>';
echo '  <th>' . s(get_string('token', 'enrol_course_tokens')) . '</th>';
echo '  <th>' . s(get_string('course', 'enrol_course_tokens')) . '</th>';
echo '  <th>' . s(get_string('createdby', 'enrol_course_tokens')) . '</th>';
echo '  <th>' . s(get_string('createdat', 'enrol_course_tokens')) . '</th>';
echo '  <th>' . s(get_string('purchaser', 'enrol_course_tokens')) . '</th>';
echo '<th>' . s(get_string('extrajson', 'enrol_course_tokens')) . '</th>';
echo '  <th>' . s(get_string('corporateaccount', 'enrol_course_tokens')) . '</th>';
echo '  <th>' . s(get_string('usedby', 'enrol_course_tokens')) . '</th>';
echo '  <th>' . s(get_string('usedat', 'enrol_course_tokens')) . '</th>';
echo '  <th>' . s(get_string('resendNewAccountEmail', 'enrol_course_tokens')) . '</th>';
echo '</tr>';
foreach ($tokens as $token) {
    echo '<tr>';
    echo '<td>' . s($token->code) . '</td>';
    echo '<td>' . s($courses[$token->course_id]) . '</td>';

    // Fetch user who created the token
    $creator = $DB->get_record('user', array('id' => $token->created_by), 'email');
    $created_by = $creator ? s($creator->email) : 'none';
    echo '<td>' . $created_by . '</td>';

    // Format "Created at" in ISO date format
    $created_at = date('Y-m-d', $token->timecreated);
    echo '<td>' . $created_at . '</td>';

    // Fetch purchaser (assigned to)
    $purchaser_email = $DB->get_field('user', 'email', array('id' => $token->user_id));
    echo '<td>' . s($purchaser_email) . '</td>';

    // Display Extra JSON (formatted or raw)
    $extra_json = !empty($token->extra_json) ? s($token->extra_json) : '-';
    echo '<td><pre>' . $extra_json . '</pre></td>';

    // Display the Corporate Account if available
    $group_account = !empty($token->group_account) ? s($token->group_account) : '-';
    echo '<td>' . $group_account . '</td>';

    // Fetch "Used By" and "Used At" details
    if (!empty($token->user_enrolments_id)) {
        // Fetch the user linked to the enrollment
        $enrollment = $DB->get_record('user_enrolments', array('id' => $token->user_enrolments_id));
        $used_by_user = $DB->get_record('user', array('id' => $enrollment->userid), 'firstname, lastname, email, phone1, address');

        if ($used_by_user) {
            $used_by = s($used_by_user->email);
            $phone = !empty($used_by_user->phone1) ? s($used_by_user->phone1) : 'N/A';
            $address = !empty($used_by_user->address) ? s($used_by_user->address) : 'N/A';
            $used_at = date('Y-m-d', $token->used_on);

            // Render the clickable "Used by" text
            $modal_trigger = html_writer::tag('a', $used_by, [
                'href' => '#',
                'data-toggle' => 'modal',
                'data-target' => '#userModal' . $enrollment->userid,
            ]);

            echo '<td>' . $modal_trigger . '</td>';
            echo '<td>' . s($used_at) . '</td>';

            // Add the modal HTML
            echo '
        <div class="modal fade" id="userModal' . $enrollment->userid . '" tabindex="-1" role="dialog" aria-labelledby="userModalLabel' . $enrollment->userid . '" aria-hidden="true">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="userModalLabel' . $enrollment->userid . '">User details</h5>
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                    <div class="modal-body">
                        <p><strong>Name:</strong> ' . s($used_by_user->firstname) . ' ' . s($used_by_user->lastname) . '</p>
                        <p><strong>Phone number:</strong> ' . $phone . '</p>
                        <p><strong>Address:</strong> ' . $address . '</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Close</button>
                    </div>
                </div>
            </div>
        </div>';
        } else {
            echo '<td>-</td>';
            echo '<td>-</td>';
        }
    } else {
        echo '<td>-</td>';
        echo '<td>-</td>';
    }
    echo '<td>';
    echo '<button class="btn btn-secondary resend-email" data-email="' . s($purchaser_email) . '" data-token="' . s($token->code) . '">Resend New Account Email</button>';
    echo '</td>';
    if (!empty($token->user_enrolments_id) && !empty($used_by_user)) {
        $user_email = s($used_by_user->email); // Get the correct enrolled user's email
        $token_id = s($token->id); // Ensure token ID is passed correctly
    
        echo '<td>
            <button type="button" class="btn btn-warning unenroll-btn"
                data-bs-toggle="tooltip"
                data-bs-placement="top"
                title="Clicking this will unenroll ' . $user_email . ' from the course. All progress will be lost. The course token can be used again after unenrolling."
                data-user-email="' . $user_email . '"
                data-token-id="' . $token_id . '">
                Unenroll
            </button>
        </td>';
    } else {
        echo '<td>-</td>';
    }
    
    if (!empty($token->id)) {
        $token_id = s($token->id);
        $is_used = !empty($token->used_on);
        
        // Use the same used_by_user logic for consistency
        $user_email = !empty($used_by_user) ? s($used_by_user->email) : null;
    
        echo '<td>';
        if ($token->voided) {
            echo '<button type="button" class="btn btn-success unvoid-token-btn"
                data-bs-toggle="tooltip"
                data-bs-placement="top"
                title="Clicking this will unvoid the token, making it usable again."
                data-token-id="' . $token->id . '">
                Unvoid Token
            </button>';
        } else {
            $tooltipText = $is_used 
                ? 'Clicking this will void the token and will unenroll ' . $user_email . ' from the course. All progress will be lost.' 
                : 'Clicking this will void the token.';
            
            echo '<button type="button" class="btn btn-danger void-token-btn"
                        data-bs-toggle="tooltip"
                        data-bs-placement="top"
                        title="' . $tooltipText . '"
                        data-token-id="' . $token->id . '"
                        data-user-email="' . ($user_email ?? '') . '"
                        data-is-used="' . ($is_used ? '1' : '0') . '">
                        Void Token
                    </button>';
        }
        echo '</td>';
    } else {
        echo '<td>-</td>';
    }    
    echo '</tr>';
}
echo '</table>';

echo '
<div class="modal fade" id="unenrollModal" tabindex="-1" aria-labelledby="unenrollModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="unenrollModalLabel">Confirm Unenrollment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <strong>Warning!</strong> Clicking this will unenroll <span id="unenrollUserEmail"></span> from the course. 
                    All progress will be lost. The course token can be used again after unenrolling.
                </div>
            </div>
            <div class="modal-footer">
                <form id="unenrollForm" action="unenroll.php" method="post">
                    <input type="hidden" name="token_id" id="unenrollTokenId">
                    <input type="hidden" name="sesskey" value="' . sesskey() . '">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Unenroll</button>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- Modal for voiding a token -->
<div class="modal fade" id="voidTokenModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Void token</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="voidTokenWarning" class="alert alert-danger"></p> <!-- Dynamic warning message -->
                <form id="voidTokenForm">
                    <input type="hidden" id="voidTokenId" name="tokenid">
                    <div class="mb-3">
                        <label for="voidNotesInput" class="form-label">Reason for voiding:</label>
                        <textarea id="voidNotesInput" name="void_notes" class="form-control" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-danger">Void Token</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- Bootstrap Modal for Unvoid Confirmation -->
<div class="modal fade" id="unvoidTokenModal" tabindex="-1" aria-labelledby="unvoidTokenModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="unvoidTokenModalLabel">Confirm unvoid token</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Are you sure you want to unvoid this token? This action will make it usable again.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="confirmUnvoidToken">Yes, Unvoid</button>
            </div>
        </div>
    </div>
</div>
';

// Add JavaScript for AJAX request
echo '<script>
    document.getElementById("email").addEventListener("blur", function() {
        var email = this.value;
        if (email) {
            var xhr = new XMLHttpRequest();
            xhr.open("POST", "ajax-check-user.php", true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            xhr.onreadystatechange = function() {
                if (xhr.readyState == 4 && xhr.status == 200) {
                    var response = JSON.parse(xhr.responseText);
                    var firstNameField = document.getElementById("firstname");
                    var lastNameField = document.getElementById("lastname");
                    var emailError = document.getElementById("emailError");

                    if (response.exists) {
                        firstNameField.value = response.firstname;
                        lastNameField.value = response.lastname;
                        firstNameField.style.borderColor = "";
                        lastNameField.style.borderColor = "";
                        emailError.textContent = "";
                    } else {
                        firstNameField.value = "New";
                        lastNameField.value = "User";
                        firstNameField.style.borderColor = "red";
                        lastNameField.style.borderColor = "red";
                        emailError.textContent = "User does not exist. Please enter first and last name.";
                    }
                }
            };
            xhr.send("email=" + encodeURIComponent(email) + "&sesskey=" + encodeURIComponent(M.cfg.sesskey));
        }
    });
    document.querySelectorAll(".resend-email").forEach(button => {
        button.addEventListener("click", function() {
            const email = this.getAttribute("data-email");
            const token = this.getAttribute("data-token");

            if (email && token) {
                fetch("resend-new-account-email.php", {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded"
                    },
                    body: `email=${encodeURIComponent(email)}&token=${encodeURIComponent(token)}&sesskey=${M.cfg.sesskey}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert("Email resent successfully.");
                    } else {
                        alert("Error resending email: " + data.error);
                    }
                });
            }
        });
    });
</script>
<script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" integrity="sha384-I7E8VVD/ismYTF4hNIPjVp/Zjvgyol6VFvRkX/vR+Vc4jQkC+hVqc2pM8ODewa9r" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.min.js" integrity="sha384-0pUGZvbkm6XF6gxjEnlmuGrJXVbNuzT9qBBavbLwCsOGabYfZo0T0to5eqruptLy" crossorigin="anonymous"></script>
<script>
    document.addEventListener("DOMContentLoaded", () => {
    initializeTooltips();
    setupUnenrollHandlers();
    setupVoidUnvoidHandlers();
});

// Initialize Bootstrap tooltips
const initializeTooltips = () => {
    document.querySelectorAll(`[data-bs-toggle="tooltip"]`).forEach(el => {
        new bootstrap.Tooltip(el);
    });
};

// Generic function to show Bootstrap modal
const showModal = (modalId) => {
    const modal = new bootstrap.Modal(document.getElementById(modalId));
    modal.show();
};

// Unenrollment handlers
const setupUnenrollHandlers = () => {
    document.querySelectorAll(".unenroll-btn").forEach(button => {
        button.addEventListener("click", () => {
            const tokenId = button.getAttribute("data-token-id");
            const userEmail = button.getAttribute("data-user-email");

            document.getElementById("unenrollTokenId").value = tokenId;
            document.getElementById("unenrollUserEmail").textContent = userEmail;

            showModal("unenrollModal");
        });
    });

    document.getElementById("unenrollForm").addEventListener("submit", async (event) => {
        event.preventDefault();
        const tokenId = document.getElementById("unenrollTokenId").value;
        if (!tokenId) {
            alert("Invalid token ID.");
            return;
        }

        try {
            const response = await fetch("unenroll.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: new URLSearchParams({ token_id: tokenId, sesskey: M.cfg.sesskey })
            });

            const result = await response.json();
            if (result.success) {
                bootstrap.Modal.getInstance(document.getElementById("unenrollModal")).hide();
                alert("User unenrolled successfully.");
                window.location.reload();
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            alert(`Error: ${error.message}`);
        }
    });
};

// Void and unvoid token handlers
const setupVoidUnvoidHandlers = () => {
    document.querySelectorAll(".void-token-btn, .unvoid-token-btn").forEach(button => {
        button.addEventListener("click", function () {
            const tokenId = this.getAttribute("data-token-id");
            const isVoiding = this.classList.contains("void-token-btn");

            if (isVoiding) {
                handleVoidToken(this, tokenId);
            } else {
                // ⛔️ Do NOT call `handleUnvoidToken()` directly!
                // Just show the confirmation modal and store the tokenId.
                selectedTokenId = tokenId;
                showModal("unvoidTokenModal");
            }
        });
    });
};

// Handle void token logic
const handleVoidToken = (button, tokenId) => {
    const userEmail = button.getAttribute("data-user-email");
    const isUsed = button.getAttribute("data-is-used") === "1";

    document.getElementById("voidTokenId").value = tokenId;
    document.getElementById("voidTokenWarning").textContent = isUsed
        ? `Clicking this will void the token and will unenroll ${userEmail} from the course. All progress will be lost.`
        : "Clicking this will void the token.";

    showModal("voidTokenModal");

    document.getElementById("voidTokenForm").onsubmit = async (event) => {
        event.preventDefault();
        const voidNotes = document.getElementById("voidNotesInput").value.trim();
        if (!voidNotes) {
            alert("Please provide a reason for voiding.");
            return;
        }

        try {
            if (isUsed) {
                await unenrollUser(tokenId);
            }
            await voidToken(tokenId, voidNotes);
            updateTokenUI(button, false);
        } catch (error) {
            alert(`Error: ${error.message}`);
        }
    };
};

// Unenroll a user via AJAX
const unenrollUser = async (tokenId) => {
    const response = await fetch("unenroll.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ token_id: tokenId, sesskey: M.cfg.sesskey, is_ajax: 1 })
    });

    const result = await response.json();
    if (!result.success) {
        throw new Error(`Failed to unenroll user: ${result.message}`);
    }
};

// Void a token via AJAX
const voidToken = async (tokenId, voidNotes) => {
    const response = await fetch("void_token.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams({ tokenid: tokenId, void_notes: voidNotes, sesskey: M.cfg.sesskey })
    });

    const result = await response.json();
    if (!result.success) {
        throw new Error(`Failed to void token: ${result.message}`);
    }

    bootstrap.Modal.getInstance(document.getElementById("voidTokenModal")).hide();
    document.getElementById("voidTokenForm").reset();
    window.location.reload();
};

// Handle unvoiding a token
const handleUnvoidToken = async (tokenId) => {
    try {
        const response = await fetch("unvoid_token.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({ token_id: tokenId, sesskey: M.cfg.sesskey })
        });

        const result = await response.json();
        if (result.success) {
            updateTokenUI(document.querySelector(`[data-token-id="${tokenId}"]`), true);
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        alert(`Error: ${error.message}`);
    }
};

// Update UI after token action
const updateTokenUI = (button, isUnvoiding) => {
    if (isUnvoiding) {
        button.classList.remove("unvoid-token-btn", "btn-success");
        button.classList.add("void-token-btn", "btn-danger");
        button.textContent = "Void Token";
        button.setAttribute("title", "Clicking this will void the token.");
    } else {
        button.classList.remove("void-token-btn", "btn-danger");
        button.classList.add("unvoid-token-btn", "btn-success");
        button.textContent = "Unvoid Token";
        button.setAttribute("title", "Clicking this will unvoid the token, making it usable again.");
    }
    window.location.reload();
};

// Handle confirm unvoid modal
let selectedTokenId = null;

// Step 1: Show modal when Unvoid Token button is clicked
document.querySelectorAll(".unvoid-token-btn").forEach(button => {
    button.addEventListener("click", function() {
        selectedTokenId = this.getAttribute("data-token-id"); 
        
        // Show the modal (Only shows the modal, does NOT unvoid yet)
        let unvoidModal = new bootstrap.Modal(document.getElementById("unvoidTokenModal"));
        unvoidModal.show();
    });
});

// Step 2: Only unvoid when user confirms
document.getElementById("confirmUnvoidToken").addEventListener("click", async () => {
    if (!selectedTokenId) return;

    try {
        let response = await fetch("unvoid_token.php", {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams({ token_id: selectedTokenId, sesskey: M.cfg.sesskey })
        });

        let result = await response.json();

        if (result.success) {
            alert(result.message);

            // Hide the modal manually after successful unvoiding
            let unvoidModal = bootstrap.Modal.getInstance(document.getElementById("unvoidTokenModal"));
            unvoidModal.hide();

            // Refresh the page to reflect changes
            window.location.reload();
        } else {
            throw new Error(result.message);
        }
    } catch (error) {
        alert("Error: " + error.message);
    }
});
</script>
';

echo $OUTPUT->footer();

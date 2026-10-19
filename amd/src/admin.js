// This file is part of Moodle - https://moodle.org/
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

/**
 * Admin page behaviour for the course tokens list.
 *
 * Tooltips and modals use the Bootstrap 5 build shipped with theme_boost
 * on Moodle 5.0 and later. This page must not load Popper or Bootstrap
 * from a CDN.
 *
 * @module     enrol_course_tokens/admin
 * @copyright  2026 William Entriken
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Config from 'core/config';
import {Modal, Tooltip} from 'theme_boost/index';

/**
 * Bind the admin page controls.
 */
export const init = () => {
    const email = document.getElementById('email');
    if (email) {
        email.addEventListener('blur', () => {
            lookupUser(email.value);
        });
    }

    initializeTooltips();
    setupUnenrollHandlers();
    setupVoidUnvoidHandlers();
    setupUnvoidConfirm();
};

/**
 * Fill the name fields when the typed email already belongs to a user.
 *
 * @param {String} email
 */
const lookupUser = (email) => {
    if (!email) {
        return;
    }
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'ajax-check-user.php', true);
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = () => {
        if (xhr.readyState !== 4 || xhr.status !== 200) {
            return;
        }
        const response = JSON.parse(xhr.responseText);
        const firstNameField = document.getElementById('firstname');
        const lastNameField = document.getElementById('lastname');
        const emailError = document.getElementById('emailError');
        if (response.exists) {
            firstNameField.value = response.firstname;
            lastNameField.value = response.lastname;
            firstNameField.style.borderColor = '';
            lastNameField.style.borderColor = '';
            emailError.textContent = '';
        } else {
            firstNameField.value = 'New';
            lastNameField.value = 'User';
            firstNameField.style.borderColor = 'red';
            lastNameField.style.borderColor = 'red';
            emailError.textContent = 'User does not exist. Please enter first and last name.';
        }
    };
    xhr.send('email=' + encodeURIComponent(email) + '&sesskey=' + encodeURIComponent(Config.sesskey));
};

/**
 * Initialise Bootstrap tooltips already marked in the page.
 */
const initializeTooltips = () => {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
        new Tooltip(el);
    });
};

/**
 * Show a Bootstrap modal by element id.
 *
 * @param {String} modalId
 * @returns {Modal}
 */
const showModal = (modalId) => {
    const modal = new Modal(document.getElementById(modalId));
    modal.show();
    return modal;
};

/**
 * Unenroll confirmation modal and its submit handler.
 */
const setupUnenrollHandlers = () => {
    document.querySelectorAll('.unenroll-btn').forEach((button) => {
        button.addEventListener('click', () => {
            const tokenId = button.getAttribute('data-bs-token-id');
            const userEmail = button.getAttribute('data-bs-user-email');
            document.getElementById('unenrollTokenId').value = tokenId;
            document.getElementById('unenrollUserEmail').textContent = userEmail;
            showModal('unenrollModal');
        });
    });

    document.getElementById('unenrollForm').addEventListener('submit', async(event) => {
        event.preventDefault();
        const tokenId = document.getElementById('unenrollTokenId').value;
        if (!tokenId) {
            window.alert('Invalid token ID.');
            return;
        }
        try {
            const response = await fetch('unenroll.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({token_id: tokenId, sesskey: Config.sesskey}),
            });
            const result = await response.json();
            if (result.success) {
                Modal.getInstance(document.getElementById('unenrollModal')).hide();
                window.alert('User unenrolled successfully.');
                window.location.reload();
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            window.alert(`Error: ${error.message}`);
        }
    });
};

/**
 * Void and unvoid buttons. Unvoid only opens the confirm modal.
 */
const setupVoidUnvoidHandlers = () => {
    document.querySelectorAll('.void-token-btn, .unvoid-token-btn').forEach((button) => {
        button.addEventListener('click', function() {
            const tokenId = this.getAttribute('data-bs-token-id');
            const isVoiding = this.classList.contains('void-token-btn');
            if (isVoiding) {
                handleVoidToken(this, tokenId);
            } else {
                selectedTokenId = tokenId;
                showModal('unvoidTokenModal');
            }
        });
    });
};

/**
 * @param {HTMLElement} button
 * @param {String} tokenId
 */
const handleVoidToken = (button, tokenId) => {
    const userEmail = button.getAttribute('data-bs-user-email');
    const canUnenroll = button.getAttribute('data-bs-can-unenroll') === '1';
    document.getElementById('voidTokenId').value = tokenId;
    document.getElementById('voidTokenWarning').textContent = canUnenroll
        ? `Clicking this will void the token and will unenroll ${userEmail} from the course. All progress will be lost.`
        : 'Clicking this will void the token.';
    showModal('voidTokenModal');
    document.getElementById('voidTokenForm').onsubmit = async(event) => {
        event.preventDefault();
        const voidNotes = document.getElementById('voidNotesInput').value.trim();
        if (!voidNotes) {
            window.alert('Please provide a reason for voiding.');
            return;
        }
        try {
            await voidToken(tokenId, voidNotes);
            updateTokenUI(button, false);
        } catch (error) {
            window.alert(`Error: ${error.message}`);
        }
    };
};

/**
 * @param {String} tokenId
 * @param {String} voidNotes
 */
const voidToken = async(tokenId, voidNotes) => {
    const response = await fetch('void_token.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({tokenid: tokenId, void_notes: voidNotes, sesskey: Config.sesskey}),
    });
    const result = await response.json();
    if (!result.success) {
        throw new Error(`Failed to void token: ${result.message}`);
    }
    Modal.getInstance(document.getElementById('voidTokenModal')).hide();
    document.getElementById('voidTokenForm').reset();
    window.location.reload();
};

/**
 * @param {HTMLElement} button
 * @param {Boolean} isUnvoiding
 */
const updateTokenUI = (button, isUnvoiding) => {
    if (isUnvoiding) {
        button.classList.remove('unvoid-token-btn', 'btn-success');
        button.classList.add('void-token-btn', 'btn-danger');
        button.textContent = 'Void Token';
        button.setAttribute('title', 'Clicking this will void the token.');
    } else {
        button.classList.remove('void-token-btn', 'btn-danger');
        button.classList.add('unvoid-token-btn', 'btn-success');
        button.textContent = 'Unvoid Token';
        button.setAttribute('title', 'Unvoiding removes the void flag. Existing usage history is preserved.');
    }
    window.location.reload();
};

let selectedTokenId = null;

/**
 * Confirm button on the unvoid modal. A second click listener on each unvoid
 * button matches the previous page script, which registered that click twice.
 */
const setupUnvoidConfirm = () => {
    document.querySelectorAll('.unvoid-token-btn').forEach((button) => {
        button.addEventListener('click', function() {
            selectedTokenId = this.getAttribute('data-bs-token-id');
            const unvoidModal = new Modal(document.getElementById('unvoidTokenModal'));
            unvoidModal.show();
        });
    });

    document.getElementById('confirmUnvoidToken').addEventListener('click', async() => {
        if (!selectedTokenId) {
            return;
        }
        try {
            const response = await fetch('unvoid_token.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({token_id: selectedTokenId, sesskey: Config.sesskey}),
            });
            const result = await response.json();
            if (result.success) {
                window.alert(result.message);
                const unvoidModal = Modal.getInstance(document.getElementById('unvoidTokenModal'));
                unvoidModal.hide();
                window.location.reload();
            } else {
                throw new Error(result.message);
            }
        } catch (error) {
            window.alert('Error: ' + error.message);
        }
    });
};

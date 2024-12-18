# STATUS

This plugin is used in a preproduction installation.

Remaining issues before public 1.0.0 release are in [Issue #49](https://github.com/fulldecent/moodle-enrol_course_tokens/issues/49).

## What is it?

This plugin lets you make single-use tokens for courses. Then anybody can use these tokens to create an account on your Moodle instance (if they don\'t already have one) and enroll in the course.

## Installation

This plugin can be installed using either `git` or Moodle's built-in plugin installation method. Follow the instructions below for your preferred method.

### Install via Git (Recommended)

1. Navigate to the root of your Moodle installation.
2. Run the following command:

   ```sh
   git clone git://github.com/fulldecent/moodle-enrol_course_tokens.git ./enrol/course_tokens
   ```

3. Optionally, add this to your `.gitignore` or local `exclude` files to prevent accidental changes:

   ```sh
   echo '/enrol/course_tokens' >> .git/info/exclude
   ```

4. Log in to your Moodle instance as *admin*. The installation process will start automatically. Alternatively, visit the *Site administration > Notifications* page to trigger the installation manually.

### Install via Moodle Plugin Installer

1. Download the plugin zip file from the [GitHub repository](https://github.com/fulldecent/moodle-enrol_course_tokens).
2. Log in to your Moodle site as *admin*.
3. Navigate to *Site administration > Plugins > Install plugins*.
4. Upload the downloaded zip file and follow the on-screen instructions.

### Post-Installation Configuration

After installation, configure the plugin:

1. Go to *Site administration > Plugins > Enrol plugins > Course Tokens*.
2. Enable the plugin to add it to the available enrolment methods.

Your plugin is now ready to use.

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
* [x] Site administrator can create tokens (/enrol/course_tokens/)
  * [x] The admin will select a course (after adding course tokens as an enrollment method) and select the desired quantity.
  * [x] Can specify arbitrary JSON to connect with this enrollment (e.g. group assignment, email opt-out)
  * [x] The token code is created automatically
    * [x] From the course ID number like cprfaaed-f7df-7781
    * [x] It can't be guessed
  * [x] Admin will assign the token directly to a new student during the token creation process.
* [ ] Activation Options
    *   This plugin provides three options to activate or use tokens for course enrollment.
    * View Tokens Page Path: `/enrol/course_tokens/view_tokens.php`
        - Click on the **"Assign"** button.
        - Provide the following details:
            - First name
            - Last name
            - Email address
    * Dashboard Block Path: `/my/`
        - Click on the **"Assign"** button in the dashboard block.
        - Provide the following details:
            - First name
            - Last name
            - Email address
    * Student Self-Enrollment Path: `/enrol/course_tokens/view_tokens.php`
        - Students can choose one of the following options:
            - **"Enroll Myself"**  
        - Enroll directly into the course using their account.
            - **"Enroll Somebody Else"**  
            - Provide the following details of the person to enroll:
                - First name
                - Last name
                - Email 
**Note:**

If the provided email does not belong to an existing user, an account will be created automatically. The user will receive:
  - An email with their username and password.
  - A welcome email upon enrollment.

If the provided email belongs to an existing user, only the welcome email will be sent.

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

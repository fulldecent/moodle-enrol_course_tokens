# Course tokens plugin

The course tokens plugin offers a simple yet powerful way to manage course enrollments in Moodle using single-use tokens. With course tokens, users can quickly create an account (if they do not already have one) and enroll in a course; no manual enrollment required.

This makes the plugin ideal for training providers, organizations, and institutions who need a secure, scalable, and flexible enrollment solution.

## Status

This plugin is used in a preproduction installation.

Remaining issues before public 1.0.0 release are in [Issue #49](https://github.com/fulldecent/moodle-enrol_course_tokens/issues/49).

Supported Moodle versions: [![CI status](https://github.com/fulldecent/moodle-enrol_course_tokens/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/fulldecent/moodle-enrol_course_tokens/actions/workflows/ci.yml?branch=main)

## Features

- **Single-use tokens for courses**
  Generate unique tokens that can be redeemed by learners to enroll in courses.

- **Easy admin token generation**
  Administrators can create and manage tokens manually from the Moodle admin interface.

- **Secure API for automated token creation**
  Automate token generation through a secure API with secret key authentication.

- **Customizable token metadata**
  Store additional information with tokens (e.g., department, reference codes, notes, or group accounts).

- **Self-service token management**
  Learners can view, manage, and redeem their available tokens from their individual token page.

- **Enrollment tracking**
  Token statuses update dynamically—showing whether they are available, assigned, in-progress, completed, or failed.

- **Supports group and corporate accounts**
  Assign tokens in bulk to teams or organizations for group enrollments.

- **Seamless Moodle integration**
   Enable course tokens as an enrollment method from `Site Administration > Plugins > Enrollment plugins`.

### Administrator token management

The `/enrol/course_tokens/index.php` page is restricted to users with the `moodle/site:config` capability. It allows administrators to create tokens, search existing tokens by token code, order number, purchaser email, or learner email, and perform supported token-management actions such as unenrolling, voiding, and unvoiding.

### Secure API for automated course token creation

Course tokens can be generated securely via an API, which requires a valid secret key for authentication. This ensures that only authorized users can create tokens. By using a unique secret key, we protect the process from unauthorized access, making this solution more secure than traditional methods. This approach ensures both flexibility and enhanced security for administrators when managing token creation programmatically.

**Required parameters:**
1. `secret_key`: string (set this in Site administration > Plugins > Enrollments > Course tokens > API secret key)
2. `course_id`: integer (The ID of the course for which tokens are being created.)
3. `email`: string (Email address of the user)
4. `quantity`: integer (Number of tokens)
5. `firstname`: string (First name of the user)
6. `lastname`: string (Last name of the user)

**Optional parameters:**
1. `extra_json`: JSON object (Additional data related to the token creation. Stored as a JSON string.)
2. `group_account`: string (Specifies the group or corporate account associated with the token.)

**cURL example with optional parameters (`group_account` and `extra_json`):**

```bash
curl 'https://example.com/enrol/course_tokens/api-do-create-token.php' \
  --header "Content-Type: application/json" \
  --data-bs-raw '{
      "secret_key": "your-api-secret-key",
    "course_id": 5,
    "email": "minicurl@example.com",
    "quantity": 1,
    "firstname": "John",
    "lastname": "Doe",
    "group_account": "Corporate Inc",
    "extra_json": {
      "department": "Sales",
      "reference_code": "ABC123",
      "notes": "Priority client"
    }
  }'
```

**cURL example without the optional parameters (i.e., `group_account` and `extra_json`):**

```bash
curl 'https://example.com/enrol/course_tokens/api-do-create-token.php' \
  --header "Content-Type: application/json" \
  --data-bs-raw '{
      "secret_key": "your-api-secret-key",
    "course_id": 5,
    "email": "minicurl@example.com",
    "quantity": 1,
    "firstname": "John",
    "lastname": "Doe",
    "group_account": "",
    "extra_json": null
  }'
```

### Configure the automated token creator user

API-created tokens must record a Moodle user in the `created_by` field.

Before using the API, configure a dedicated service account user ID:

1. Create a dedicated Moodle account, for example `Course Token Service Account`.
2. Copy that account's Moodle user ID.
3. Go to `Site administration > Plugins > Enrollments > Course tokens`.
4. Set `Automated token creator user ID` to that user ID.
5. Keep this account active and do not use the guest account.

Manual token creation from the Moodle UI still records the currently logged-in user as the token creator.

### How to generate a secret key

Set the value in `Site administration > Plugins > Enrollments > Course tokens > API secret key`.

The API request sends the key using the JSON field `secret_key`.

If you rotate the key, update any external API clients to send the new value in the existing `secret_key` JSON field.

### User token management

The `/enrol/course_tokens/view_tokens.php` page allows logged-in token purchasers to view and manage the tokens associated with their account. It shows each token's code, course, status, assigned learner, usage date, skills schedule date, and available eCard actions. Available tokens can be used to enroll the purchaser or another learner.

### Token display callback

Other Moodle plugins may customise token presentation by implementing this callback in their `lib.php` file:

```php
function local_example_enrol_course_tokens_extend_token_display(array $context): array {
    return [
        'status_code' => 'completed',
    ];
}
```

The callback may return any of these fields: `status_code`, `status_label`, `status_class`, `ecard_html`, and `forward_html`. A `status_code` must be one of `available`, `assigned`, `in_progress`, `completed`, or `failed`.

Providers execute in ascending Moodle component-name order. Each valid, non-empty return value replaces the value produced so far, so the alphabetically later component wins a conflict. All fields, including `ecard_html` and `forward_html`, are replacement-only and are never appended. The merged values are passed in `$context` to the next provider. When no provider is registered, the normal token display remains unchanged.

### :gear: Site administration page

Enable course tokens as an enrollment method by navigating to `Site administration > Plugins > Enrollment plugins > Course Tokens`.

## Placement of the plugin

The course tokens plugin can be used in several ways:

- **Direct enrollment via tokens**: Users redeem a token and are immediately enrolled in the associated course.
- **User account creation**: If a learner doesn’t yet have a Moodle account, they can create one during token redemption.
- **Administrator token management**: Administrators can create, search, and manage tokens from `/enrol/course_tokens/index.php`.
- **User token management**: Logged-in token purchasers can view and use their tokens from `/enrol/course_tokens/view_tokens.php`.
- **API integration**: Training providers can integrate course tokens with their existing sales or CRM systems to issue tokens automatically.

## Quick start playground

:runner: Run a Moodle playground site with *Course tokens* on your own computer in under 5 minutes! Zero programming or Moodle experience required.

These instructions include code snippets that you will need to copy/paste into your command terminal. On macOS that would be Terminal.app, which is a software you already have installed.

1. Install a Docker system:

   1. On macOS we currently recommend [OrbStack](https://orbstack.dev/). This is the only software which can install Moodle in under 5 minutes. We would prefer if an open source product can provide this experince, but none such exists. See [references](#references) below if you may prefer another option.
   2. On Windows (TODO: add open source recommendation)
   3. On Linux (TODO: add open source recommendation)

2. Create a Moodle testing folder. You will use this to test this plugin, but you could also mix in other plugins onto the same system if you like.

   ```sh
   cd ~/Developer
   mkdir moodle-playground && cd moodle-playground
   ```

3. Install the latest version of Moodle:

   ```sh
   # Visit https://moodledev.io/general/releases to find the latest release, like X.Y.

   export BRANCH=MOODLE_X0Y_STABLE # update X and Y here to match the latest release version
   git clone --depth=1 --branch $BRANCH git://git.moodle.org/moodle.git
   ```

   *:information_source: If you see the error "fatal: Remote branch MOODLE_X0Y_STABLE not found in upstream origin", please reread instruction in the code comment and try again.*

   *These instructions include a workaround for [Moodle issue MDL-83812](https://tracker.moodle.org/browse/MDL-83812).*

4. Install the Course tokens plugin into your Moodle playground:

   ```sh
   git clone https://github.com/fulldecent/moodle-enrol_course_tokens.git moodle/enrol/course_tokens
   ```

5. Get and run Moodle Docker container (instructions adapted from [moodle-docker instructions](https://github.com/moodlehq/moodle-docker)):

   ```sh
   git clone https://github.com/moodlehq/moodle-docker.git
   cd moodle-docker # You are now at ~/Developer/moodle-playground/moodle-docker

   export MOODLE_DOCKER_WWWROOT=../moodle
   export MOODLE_DOCKER_DB=pgsql
   bin/moodle-docker-compose up -d
   bin/moodle-docker-wait-for-db

   cp config.docker-template.php $MOODLE_DOCKER_WWWROOT/config.php
   bin/moodle-docker-compose exec webserver php admin/cli/install_database.php --agree-license --fullname="Docker moodle" --shortname="docker_moodle" --summary="Docker moodle site" --adminpass="test" --adminemail="admin@example.com" --adminuser='admin'
   ```

   *:information_source: If you see the error "Database tables already present; CLI installation cannot continue", please follow the "teardown" instructions below and then try again.*

   *:information_source: If you see the error "!!! Site is being upgraded, please retry later. !!!", and "Error code: upgraderunning…", please ignore the error and proceed.*

   *These instructions include a workaround for [moodle-docker issue #307](https://github.com/moodlehq/moodle-docker/issues/307).*

6. :sun_with_face: Now play with your server at <http://localhost:8000>

   1. Click the top-right to login.
   2. Your username is `admin` and your password is `test`.

   *:information_source: If you see a bunch of stuff and "Update Moodle database now", then click that button and wait. On a M1 Mac with 8GB ram, we saw this take 5 minutes for the page to finish loading.*

7. To completely kill your playground so that next time you will start with a blank slate:

   ```sh
   bin/moodle-docker-compose down --volumes --remove-orphans
   colima stop
   ```

If you have any further questions about the playground setup, customizing it or other error messages, please see [moodle-docker documentation](https://github.com/moodlehq/moodle-docker) and [contact that team](https://github.com/moodlehq/moodle-docker/issues).

## Install

Install the Course tokens on your quality assurance or production server the same way as on the playground:

1. Clone the plugin into Moodle's enrollment plugin directory:

   ```sh
   git clone https://github.com/fulldecent/moodle-enrol_course_tokens.git enrol/course_tokens
   ```

2. Load your website in the browser to set up plugins.

## Updating JavaScript

*You only need these instructions if you contribute changes to this Course tokens plugin, specifically the functionality in JavaScript.*

This project uses asynchronous module definition (AMD) to compile JavaScript. This improves performance of modules and is a best practice for Moodle modules [CITATION NEEDED].

1. Install Node (we recommend using [nvm](https://github.com/nvm-sh/nvm))

   1. See the required version in your package.json file:

      ```sh
      cd ~/Developer/moodle-playground/moodle/enrol/course_tokens
      cat ../../package.json | grep '"node"'
      ```

2. Install a Node package manager (we recommend [Yarn Berry](https://github.com/yarnpkg/berry)).

   ```sh
   corepack enable
   ```

3. Install packages

   ```sh
   yarn install
   ```

4. Run the Grunt script to rebuild the AMD module

   ```sh
   yarn exec grunt amd
   ```

The end result is that files in `amd/build` will be generated from source files in [amd/src](amd/src). The generated `amd/build` directory is currently excluded by this repository's `.gitignore`.

## Contributing

Please send PRs to our [main branch](https://github.com/fulldecent/moodle-enrol_course_tokens).

## References

1. This module is built based on [best practices documented in moodle-local_plugin_template](https://github.com/fulldecent/moodle-local_plugin_template).
2. Setting up Docker
   1. We would prefer an open-source-licensed Docker implementation that runs at native speed on Mac, Linux and Windows. For Mac, you may prefer to [install Colima](https://github.com/abiosoft/colima?tab=readme-ov-file#installation) which is open source but about 5x slower than the OrbStack recommended above.
3. Setting up playground
   1. If you require a few courses and users to test your plugin, you may want to look at the [generator tool](https://moodledev.io/general/development/tools/generator).
4. Continuous integration
   1. This plugin uses GitHub Actions for continuous integration.
   2. The workflow is defined in `.github/workflows/ci.yml` and performs a lightweight PHP syntax check on pull requests targeting `main` and on pushes to `main`.
   3. The CI status badge near the top of this README reflects the status of the `main` branch and links directly to this repository's workflow.
5. JavaScript modules in Moodle. For guidance on AMD modules, see the [Moodle JavaScript Modules Documentation](https://moodledev.io/docs/4.5/guides/javascript/modules).

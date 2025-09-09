# Course Tokens Plugin

The Course Tokens Plugin offers a simple yet powerful way to manage course enrollments in Moodle using single-use tokens. With course tokens, users can quickly create an account (if they don’t already have one) and enroll in a course—no manual enrolment required.

This makes the plugin ideal for training providers, organizations, and institutions who need a secure, scalable, and flexible enrollment solution.

## Status

This plugin is used in a preproduction installation.

Remaining issues before public 1.0.0 release are in [Issue #49](https://github.com/fulldecent/moodle-enrol_course_tokens/issues/49).

Supported Moodle versions: ![CI status](https://github.com/fulldecent/moodle-local_plugin_template/actions/workflows/ci.yml/badge.svg)

## Features

- **Single-use tokens for courses**
  Generate unique tokens that can be redeemed by learners to enroll in courses.

- **Easy admin token generation**
  Administrators can create and manage tokens manually from the Moodle admin interface.

- **Secure API for automated token creation**
  Automate token generation through a secure API with secret key authentication.

- **Customizable token metadata**
  Store additional information with tokens (e.g., department, reference codes, notes, or group accounts).

- **Self-service dashboard for users**
  Learners can easily view, manage, and redeem their available tokens through a clean dashboard.

- **Enrollment tracking**
  Token statuses update dynamically—showing whether they are available, assigned, in-progress, completed, or failed.

- **Supports group and corporate accounts**
  Assign tokens in bulk to teams or organizations for group enrollments.

- **Seamless Moodle integration**
  Enable Course Tokens as an enrollment method from `Site Administration > Plugins > Enrol plugins`.

Only administrators have access to this page, where they can manually generate tokens for course enrollment. This allows for customized enrollment management within the system.

### Secure API for automated course token creation

Course tokens can be generated securely via an API, which requires a valid secret key for authentication. This ensures that only authorized users can create tokens. By using a unique secret key, we protect the process from unauthorized access, making this solution more secure than traditional methods. This approach ensures both flexibility and enhanced security for administrators when managing token creation programmatically.

**Required parameters:**
1. `secret_key`: string ([Here is how to generate one](#how-to-generate-a-secret-key))
2. `course_id`: integer (The ID of the course for which tokens are being created.)
3. `email`: string (Email address of the user)
4. `quantity`: integer (Number of tokens)
5. `firstname`: string (First name of the user)
6. `lastname`: string (Last name of the user)

**Optional parameters:**
1. `extra_json`: JSON object (Additional data related to the token creation. Stored as a JSON string.)
2. `group_account`: string (Specifies the group or corporate account associated with the token.)

**cURL example ith optional parameters (`group_account` and `extra_json`):**

```bash
curl 'https://learn.pacificmedicaltraining.com/enrol/course_tokens/api-do-create-token.php' \
  --header "Content-Type: application/json" \
  --data-raw '{
    "secret_key": "secret_key",
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
curl 'https://learn.pacificmedicaltraining.com/enrol/course_tokens/api-do-create-token.php' \
  --header "Content-Type: application/json" \
  --data-raw '{
    "secret_key": "secret_key",
    "course_id": 5,
    "email": "minicurl@example.com",
    "quantity": 1,
    "firstname": "John",
    "lastname": "Doe",
    "group_account": "",
    "extra_json": null
  }'
```

### How to generate a secret key

Follow these steps to generate a secure secret_key for your plugin:
1. Create a file named `key.php` in the `course_tokens` directory.
2. Add the following code to `key.php`:

```php
<?php
require_once('/var/www/vhosts/moodle/config.php');
require_login();
require_capability('moodle/site:config', context_system::instance());

// Set the secret key for the plugin
$plugin_name = 'course_token'; // Replace with your plugin's name
$secret_key = bin2hex(random_bytes(16)); // Generate a secure random key
set_config('secretkey', $secret_key, $plugin_name);

echo "Secret key for plugin '{$plugin_name}' has been set to: {$secret_key}";
```

3. Run the `key.php` file by accessing it in your browser or command line.

Example:

`https://yourmoodlesite.com/enrol/course_tokens/key.php`

4. The `secret_key` will be displayed on your screen. Save it securely, as it will be required for all API requests.

### View tokens

The `view_tokens.php` page allows users to view and manage their course enrollment tokens. Users can see detailed information about each token, including its status, associated course, and usage details. For tokens marked as "Available," users can either enroll themselves in the course or enroll someone else using a simple form. The page dynamically updates token statuses, such as "In-progress," "Completed," or "Failed," based on course activity and exam results. This interface ensures a streamlined and user-friendly experience for managing course enrollments.

### Tokens dashboard page

The file dashboard.php displays a user-friendly dashboard summarizing the availability and status of course tokens for the logged-in user. It fetches token data, groups it by course, and categorizes them into statuses such as "Available," "Assigned," "In-progress," "Completed," and "Failed." Users can assign tokens to themselves or others through modals with a simple form, and the status updates dynamically. The page uses AJAX for smooth token assignment and displays detailed course and token information in a structured, Bootstrap-styled table.

### Users can view the "Token dashboard" on their dashboard page

This plugin allows users to add a Moodle block to their page that mirrors the functionality of the `dashboard.php` file. To create the block, simply run the following command after installing the plugin:

```sh
ln -s /enrol/course_tokens/block /blocks/
```

This will generate a "Course Tokens" block with the same features and information as the original dashboard page.

### :gear: Site administration page

Enable course tokens as an enrollment method by navigating to `Site administration > Plugins > Enrol plugins > Course Tokens`.

## Placement of the Plugin

The Course Tokens plugin can be used in several ways:

- **Direct enrollment via tokens**: Users redeem a token and are immediately enrolled in the associated course.
- **User account creation**: If a learner doesn’t yet have a Moodle account, they can create one during token redemption.
- **Dashboard and block access**: Learners can view their available tokens from the Token Dashboard page or by adding the Course Tokens block to their dashboard.
- **API integration**: Training providers can integrate Course Tokens with their existing sales or CRM systems to issue tokens automatically.

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

1. ```sh
   git clone https://github.com/fulldecent/moodle-enrol_course_tokens.git enrol/course_tokens
   ```

2. Load your website in the browser to set up plugins.

## Updating JavaScript

*You only need these instructions if you contribute changes to this Course toknes plugin, specifically the functionality in JavaScript.*

This project uses asynchronous module definition (AMD) to compile JavaScript. This improves performance of modules and is a best practice for Moodle modules [CITATION NEEDED].

1. Install Node (we recommend using [nvm](https://github.com/nvm-sh/nvm))

   1. See the required version in your package.json file:

      ```sh
      cd ~/Developer/moodle-playground/moodle/enrol/course_tokens
      cat ../../package.json | grep '"node"'
      ```

2. Install a Node package manager (we recommend [Yarn Berry](https://github.com/yarnpkg/berry)).

   ```shcorepack enable
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

The end result is that your files in [amd/build](amd/build) will be updated, assuming you have made changes to your files in [amd/source](amd/source).

Do commit these built artifacts in your repository (do not gitignore the amd/build directory). Yes, this is a violation of DRY principle. This is called "production mode" and it is a documented best practice for Moodle modules [CITATION NEEDED].

## Contributing

Please send PRs to our [main branch](https://github.com/fulldecent/moodle-enrol_course_tokens).

## References

1. This module is built based on [best practices documented in moodle-local_plugin_template](https://github.com/fulldecent/moodle-local_plugin_template).
2. Setting up Docker
   1. We would prefer an open-source-licensed Docker implementation that runs at native speed on Mac, Linux and Windows. For Mac, you may prefer to [install Colima](https://github.com/abiosoft/colima?tab=readme-ov-file#installation) which is open source but about 5x slower than the OrbStack recommended above.
3. Setting up playground
   1. If you require a few courses and users to test your plugin, you may want to look at the [generator tool](https://moodledev.io/general/development/tools/generator).
4. Continuous integration
   1. This plugin uses [the Moodle CI suite recommended by Catalyst](https://github.com/catalyst/catalyst-moodle-workflows)
   2. Perhaps we would prefer the CI suite provided by Moodle, but their approach [does not allow you to set it once and forget it](https://github.com/moodlehq/moodle-plugin-ci/issues/323).
   3. If you face issues with CI during the build, refer to the [Catalyst README](https://github.com/catalyst/catalyst-moodle-workflows/tree/bbb7b5fba5f8304b8b07ad5534b666202d1751c8?tab=readme-ov-file#amd--grunt-bundling-issues) for troubleshooting tips.
5. JavaScript modules in Moodle. For best practices on how to use JavaScript modules in Moodle,
  including the use of AMD for asynchronous loading, check the [Moodle JavaScript Modules Documentation](https://moodledev.io/docs/4.5/guides/javascript/modules). We recommend including the amd/build folder in your repo with your build files. This is not DRY, it is "production mode". Examples of other Moodle modules recommending this best practice are [h5p plugin](https://github.com/h5p/moodle-mod_hvp), [attendance plugin](https://github.com/danmarsden/moodle-mod_attendance/tree/MOODLE_404_STABLE/amd).
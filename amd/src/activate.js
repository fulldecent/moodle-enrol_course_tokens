// TODO: make javascript work in the Moodle way
//
// documentation: https://moodledev.io/docs/guides/javascript/modules
//
// bug report: https://github.com/moodlehq/moodle-docker/issues/287 

export const init = () => {
    window.console.log('we have been started');
};

/*
define(['jquery'], function($) {
    return {
        init: function() {
            console.log('AMD module enrol_course_tokens/activate is loaded and working!');
            // You can start building your module functionality from here
        }
    };
});
*/
<?php

return [
    /*
     * Makes the email sender fail on purpose, to show retries and the dead letter queue.
     * Leave it false. The worker reads it at start-up, so restart the worker after changing it.
     */
    'email_fail' => (bool) env('NOTIFICATIONS_EMAIL_FAIL', false),
];

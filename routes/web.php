<?php

// Intentionally empty. The pages of the web UI are served by Modules/Web, without the "web" middleware group
// (no session, no CSRF). This file stays because bootstrap/app.php, which is frozen, still points at it.

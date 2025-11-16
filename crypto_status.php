<?php
define('INCLUDED_IN_PAGE', true);
require 'includes/crypto.php';
echo get_crypto() ? 'KEY_OK' : 'KEY_MISSING';
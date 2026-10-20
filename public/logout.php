<?php
require_once __DIR__ . '/../src/auth.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') { check_csrf(); end_session(); }
header('Location: login.php');

<?php
require __DIR__ . '/../src/app.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') { check_csrf(); end_session(); }
header('Location: login.php');

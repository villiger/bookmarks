<?php
function page_head(string $title): void { ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex"><title><?= h($title) ?> · Bookmarks</title><link rel="stylesheet" href="style.css"></head><body>
<?php }

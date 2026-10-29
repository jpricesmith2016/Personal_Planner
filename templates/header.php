<?php if (session_status() === PHP_SESSION_NONE) session_start(); ?>
<!DOCTYPE html>
<html>
<head>
    <title>Personal Planner</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="container">
    <h1>Personal Planner</h1>

    <?php if (isset($_SESSION['user_id'])): ?>
        <div class="nav">
            <a href="dashboard.php">Dashboard</a> |
            <a href="logout.php">Logout</a>
        </div>
    <?php endif; ?>

    <hr>

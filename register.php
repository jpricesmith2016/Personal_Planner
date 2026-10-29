<?php
session_start();
require_once 'includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (createUser($username, $password)) {
        header("Location: login.php");
        exit;
    } else {
        $error = "Registration failed (username may exist)";
    }
}
?>

<?php include 'templates/header.php'; ?>

<h2>Register</h2>

<form method="POST">
    <input name="username" placeholder="Username" required><br>
    <input type="password" name="password" placeholder="Password" required><br>
    <button type="submit">Register</button>
</form>

<?php if (isset($error)) echo "<p>$error</p>"; ?>

<?php include 'templates/footer.php'; ?>

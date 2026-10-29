<?php
session_start();
require_once 'includes/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = fetchUserByUsername($_POST['username']);

    if ($user && password_verify($_POST['password'], $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Invalid login";
    }
}
?>

<?php include 'templates/header.php'; ?>

<h2>Login</h2>

<form method="POST">
    <input name="username" required><br>
    <input type="password" name="password" required><br>
    <button type="submit">Login</button>
</form>

<?php if (isset($error)) echo "<p>$error</p>"; ?>

<?php include 'templates/footer.php'; ?>

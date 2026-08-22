<p>This server has no <code>config.php</code> yet. Nothing has been written to disk —
choose your settings and press the button, and the next page will show you the API key
<strong>once</strong>.</p>
<?= $summary ?>
<form method="post" action="<?= $safeAction ?>">
<?= $fields ?>
<p><button type="submit">Write config.php</button></p>
</form>
<p class="muted">Every one of these is a plain line in <code>config.php</code> afterwards,
with a comment explaining it. Nothing here is a one-way door.</p>

<p>This server is configured. The key for <code><?= $safeKeyId ?></code> was shown once, on
<?= $shown ?> UTC, and is not shown again.</p>
<?= $open ?>
<h2>If you need the key</h2>
<p>It is in <code>config.php</code> in this directory. Read it over SSH or FTP.</p>
<h2>If you want a new one</h2>
<p>Delete <code>config.php</code> and reload
<a href="<?= $safeBase ?>/install"><?= $safeBase ?>/install</a>. Chunks already stored keep working —
they are downloaded without authentication — but nothing uploaded under the old key can be
deleted through the API any more.</p>
<p class="muted"><a href="<?= $safeBase ?>/">Server status</a></p>

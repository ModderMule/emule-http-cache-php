<p class="box bad">Nothing was written: <?= $safeError ?></p>
<?= $hints ?>
<h2>Or install it by hand</h2>
<pre><code>cp config.example.php config.php
php -r 'echo bin2hex(random_bytes(24)), "\n";'</code></pre>
<p>Paste that value over the <code>secret</code> in <code>config.php</code>. A config you
wrote yourself is never read back by this page.</p>
<p class="muted">Once it works, tighten the permissions again — the web server only needed
to write here for this one request. <a href="<?= $safeBase ?>/install">Try again</a></p>

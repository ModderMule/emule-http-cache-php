<p class="box warn"><strong>This is the only time the key is shown.</strong> Copy it now.
If you lose it, it is still in <code>config.php</code> on the server; if you cannot read that
file either, delete it and reload <a href="<?= $safeBase ?>/install">/install</a> for a new one.</p>
<?= $claim ?>
<h2>Your API key</h2>
<table>
  <tr><td>key id</td><td><code><?= $safeKeyId ?></code></td></tr>
  <tr><td>secret</td><td><span class="key"><?= $safeSecret ?></span></td></tr>
  <tr><td>base URL</td><td><code><?= $safeBase ?></code></td></tr>
</table>
<?= $open ?>
<h2>Configure eMuleQt in one click</h2>
<p><a href="<?= $safeLink ?>"><?= $safeLink ?></a></p>
<p class="muted">This link carries the secret. Treat it exactly like the key itself: it is
for your own client, not for a forum post. See <code>docs/ed2k-httpcache-link.md</code>.</p>
<h2>Or by hand</h2>
<pre><code>httpCache:
  enabled: true
  baseUrl: "<?= $safeBase ?>"
  apiKey: "<?= $safeSecret ?>"</code></pre>
<h2>Worth doing next</h2>
<ul>
  <li>Check <a href="<?= $safeBase ?>/v1/info"><?= $safeBase ?>/v1/info</a> answers.</li>
  <li>Put <code>bin/gc.php</code> on cron as the web server user — see <code>README.md</code>.
      Without it, nothing reclaims expired chunks on a quiet server.</li>
  <li>If the web server user still owns this directory from the install, tighten it again.</li>
</ul>

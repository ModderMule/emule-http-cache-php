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
<h2>Configure eMuleQt</h2>
<p><button type="button" class="copy" id="copyLink">Copy the link</button>
<span class="muted" id="copyNote" hidden></span></p>
<p><code id="ed2kLink"><?= $safeLink ?></code></p>
<p>eMuleQt watches the clipboard for these links, so copying one is the whole setup: it
handshakes with this server and asks before it stores anything. If nothing happens, check
Options &rarr; &ldquo;Watch clipboard for eD2K links&rdquo;, which is on by default.</p>
<p class="muted">This link carries the secret. Treat it exactly like the key itself: it is
for your own client, not for a forum post. See <code>docs/ed2k-httpcache-link.md</code>.</p>
<script>
(() => {
  const button = document.getElementById('copyLink');
  const source = document.getElementById('ed2kLink');
  const note = document.getElementById('copyNote');

  const report = (ok) => {
    note.textContent = ok
      ? 'copied — switch to eMuleQt'
      : 'could not copy; select the link and copy it by hand';
    note.hidden = false;
  };

  // navigator.clipboard needs a secure context and this server is often plain
  // http on a LAN, where it is simply absent. execCommand still works there.
  const copyByHand = (text) => {
    const scratch = document.createElement('textarea');
    scratch.value = text;
    scratch.setAttribute('readonly', '');
    scratch.style.position = 'fixed';
    scratch.style.opacity = '0';
    document.body.appendChild(scratch);
    scratch.select();

    try {
      return document.execCommand('copy');
    } catch {
      return false;
    } finally {
      document.body.removeChild(scratch);
    }
  };

  button.addEventListener('click', async () => {
    // The link is read out of the DOM rather than templated in here, so the
    // secret never has to survive a second round of escaping.
    const text = source.textContent;

    if (window.isSecureContext && navigator.clipboard) {
      try {
        await navigator.clipboard.writeText(text);
        report(true);
        return;
      } catch {
        // Refused, or no permission for it. The textarea below still works.
      }
    }

    report(copyByHand(text));
  });
})();
</script>
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

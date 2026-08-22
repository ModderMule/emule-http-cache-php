<p>Encrypted chunk cache for eMuleQt. This server stores AES-256-CBC ciphertext
and never receives a key, a file hash or a part number.</p>
<table>
  <tr><td><code>GET</code></td><td><a href="<?= $safeBase ?>/v1/info"><?= $safeBase ?>/v1/info</a></td></tr>
  <tr><td><code>POST</code></td><td><code><?= $safeBase ?>/v1/chunks</code></td></tr>
  <tr><td><code>GET</code></td><td><code><?= $safeBase ?>/v1/chunks/{id}</code></td></tr>
  <tr><td><code>DELETE</code></td><td><code><?= $safeBase ?>/v1/chunks/{id}</code> &mdash; auth required</td></tr>
</table>
<?= $auth ?>
<p>See <code>README.md</code> for the full contract.</p>

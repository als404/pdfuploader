<div id="pdfuploader-cmp" class="container" style="padding:16px">
  <h2>Загрузка PDF и превью (WebP 270×382)</h2>
  <div class="small text-muted" style="margin:8px 0 16px">
    По умолчанию папка: <code>{$default_folder}</code>
    {if $rid} · Ресурс: <code>#{$rid}</code>{/if}
  </div>
  <iframe
    src="{$assets_url}mgr-panel.html"
    style="width:100%;height:820px;border:none;background:#fff;">
  </iframe>
</div>

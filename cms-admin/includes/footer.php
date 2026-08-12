<?php
declare(strict_types=1);

$cmsJsPath    = dirname(__DIR__) . '/assets/js/admin.js';
$cmsJsVersion = @filemtime($cmsJsPath) ?: 1;
?>
    </div><!-- /.admin-content -->
    </div><!-- /.admin-main -->
    <div class="admin-sidebar__scrim" id="admin-sidebar-scrim" hidden></div>
</div><!-- /.admin-app -->
<script src="<?= cms_esc(cms_asset_url('js/admin.js')) ?>?v=<?= (int) $cmsJsVersion ?>" defer></script>
</body>
</html>

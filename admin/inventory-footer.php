        </main><!-- /.ui-page -->
    </div><!-- /.content -->

    <button id="backToTopBtn" class="back-to-top" onclick="window.scrollTo(0,0)" title="Back to top" aria-label="Back to top">
        <i class="fas fa-arrow-up"></i>
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <?php
    // sidebar-admin.php normally emits ui.js already; this is the
    // fallback for any page that renders the footer without a sidebar.
    if (!defined('MX_UI_JS_EMITTED')) {
        define('MX_UI_JS_EMITTED', true);
        echo '<script src="../assets/js/admin/ui.js"></script>' . "\n";
    }
    ?>
    <?php if (!empty($pageScript)) { echo $pageScript; } ?>
</body>
</html>

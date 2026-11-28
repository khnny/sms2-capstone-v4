<?php
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; } // include-only file
/**
 * SMS 2 - Authenticated Layout End
 */
?>
        </main>
        <?php require_once ROOT_PATH . '/includes/footer.php'; ?>
    </div>
</div>
<?php require_once ROOT_PATH . '/includes/scripts.php'; ?>
<?php require_once ROOT_PATH . '/includes/assignment-confirmation-modal.php'; ?>

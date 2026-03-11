<?php
// inc/layout_bottom.php : (표준) 공용 푸터/레이아웃 끝
?>
</div>

<!-- 공용 JS -->
<script defer src="<?php echo h(dp_url('assets/dp_userbar.js')); ?>"></script>
<script defer src="<?php echo h(dp_url('assets/dp_sidebar.js')); ?>"></script>

<?php if (!empty($ENABLE_MATRIX_BG)): ?>
  <script src="<?php echo h(dp_url('assets/matrix-bg.js')); ?>"></script>
<?php endif; ?>

</body>
</html>

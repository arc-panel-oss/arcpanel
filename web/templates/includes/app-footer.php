<footer class="app-footer">
	<div class="container">
		<p>
			<a href="https://arcpanel.top/" class="app-footer-link" target="_blank">
				ArcPanel
			</a>
			v<?= tohtml(($_SESSION["ARCPANEL_VERSION"] ?? "") ?: $_SESSION["VERSION"]) ?>
			&middot; <?= tohtml(sprintf(_("Based on HestiaCP %s"), $_SESSION["VERSION"])) ?>
		</p>
	</div>
</footer>

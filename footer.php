<!-- 3. COMMON FOOTER -->
<footer class="mt-auto py-3 text-center border-top bg-white">
    <p class="mb-0 text-muted" style="font-size: 0.85rem;">
        &copy; <?= date("Y") ?> <strong>Dit Solutions</strong>. All Rights Reserved.
    </p>
</footer>

</div> <!-- End of #main-content -->

<!-- Bootstrap Bundle JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
    // Sidebar Toggle Logic
    const btn = document.getElementById('mobile-toggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const closeBtn = document.getElementById('close-sidebar');
    
    function toggleSidebar() {
        sidebar.classList.toggle('active');
        if(overlay) overlay.classList.toggle('show');
    }

    if(btn) btn.onclick = toggleSidebar;
    if(closeBtn) closeBtn.onclick = toggleSidebar;
    if(overlay) overlay.onclick = toggleSidebar;

    // Submenu Accordion Logic
    document.querySelectorAll('.parent-toggle').forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const submenu = this.nextElementSibling;
            if (submenu) {
                const isVisible = window.getComputedStyle(submenu).display !== 'none';
                
                // Close other submenus for a cleaner look
                document.querySelectorAll('.submenu').forEach(s => s.style.display = 'none');
                document.querySelectorAll('.parent-toggle').forEach(p => p.classList.remove('open'));

                if (!isVisible) {
                    submenu.style.display = 'block';
                    this.classList.add('open');
                }
            }
        });
    });
</script>

<style>
    /* Footer & Layout Fixes */
    html, body {
        height: 100%;
        margin: 0;
    }

    #main-content {
        display: flex;
        flex-direction: column;
        min-height: 100vh;
        padding-top: 65px; /* Matches navbar height */
    }

    main {
        flex: 1; /* Takes all available space pushing footer to bottom */
    }

    footer {
        background: #fff;
        text-align: center;
    }

    .parent-toggle.open .bi-chevron-down {
        transform: rotate(180deg);
        transition: 0.3s;
    }
</style>
</body>
</html>
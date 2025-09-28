  </main>
</header>
<script>
  // Mark active nav link by ?page param (default 'home')
  (function(){
    try{
      var params = new URLSearchParams(location.search);
      var page = params.get('page') || 'home';
      // Also treat "/" as home
      if (location.pathname === '/' && !params.get('page')) page = 'home';
      document.querySelectorAll('.primary-nav a[data-page]').forEach(function(a){
        if(a.dataset.page === page){ a.classList.add('active'); }
      });
    }catch(e){}
  })();
</script>
</body>
</html>

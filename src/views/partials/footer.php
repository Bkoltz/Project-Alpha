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
  // CSRF auto-inject for all POST forms
  (function(){
    try{
      var meta = document.querySelector('meta[name="csrf-token"]');
      var token = meta ? meta.getAttribute('content') : '';
      if(!token) return;
      document.addEventListener('submit', function(e){
        var f = e.target;
        if (f && f.tagName === 'FORM' && (f.method||'').toLowerCase() === 'post'){
          if (!f.querySelector('input[name="csrf"]')){
            var i = document.createElement('input');
            i.type='hidden'; i.name='csrf'; i.value=token; f.appendChild(i);
          }
        }
      }, true);
    }catch(e){}
  })();
</script>
</body>
</html>

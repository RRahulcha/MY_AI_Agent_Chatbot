<footer class="footer">
          <div class="d-sm-flex justify-content-center justify-content-sm-between">
            <span class="text-muted text-center text-sm-left d-block d-sm-inline-block">Copyright © <?php echo date('Y') ?>. Developed & Maintained  by <a href="https://settribe.com/" target="_blank">SETTribe</a></span>
          </div>
        </footer>

        <?php if (isset($_SESSION['userId'])): 
          $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])) : '';
          $appRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
          $webPath = ($docRoot && strpos($appRoot, $docRoot) === 0) ? substr($appRoot, strlen($docRoot)) : '';
          $firebaseJs = rtrim(str_replace('\\', '/', $webPath), '/') . '/js/firebase-init.js';
        ?>
          <script type="module" src="<?php echo $firebaseJs; ?>"></script>
        <?php endif; ?>

        <?php if (isset($_SESSION['login_success'])): ?>
<script>
  function showLoginNotification() {
    var title = "Login Successful";
    var options = { body: "Welcome to AMS" };
    if ("Notification" in window) {
      if (Notification.permission === "granted") {
        if (navigator.serviceWorker) {
          navigator.serviceWorker.ready.then(function(reg) {
            reg.showNotification(title, options);
          }).catch(function() {
            new Notification(title, options);
          });
        } else {
          new Notification(title, options);
        }
      } else if (Notification.permission !== "denied") {
        Notification.requestPermission().then(permission => {
          if (permission === "granted") {
            if (navigator.serviceWorker) {
              navigator.serviceWorker.ready.then(function(reg) {
                reg.showNotification(title, options);
              }).catch(function() {
                new Notification(title, options);
              });
            } else {
              new Notification(title, options);
            }
          }
        });
      }
    }
  }
  showLoginNotification();
</script>
<?php unset($_SESSION['login_success']); endif; ?>

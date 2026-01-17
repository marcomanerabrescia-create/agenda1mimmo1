<?php echo __DIR__ . '/send-onesignal-push.php'; ?>
```

**4. Apri nel browser:**
```
https://www.consulenticaniegatti.com/app/prenotazioni/MULETTO/PRENOTAZIONI/path.php
```

**5. Ti dirà una cosa tipo:**
```
/home/consulenticaniegatti/public_html/app/prenotazioni/MULETTO/PRENOTAZIONI/send-onesignal-push.php
```

**6. Copia quel percorso**

**7. Vai nelle impostazioni del cron job** e invece di mettere l'URL metti:
```
/usr/bin/php /home/consulenticaniegatti/public_html/app/prenotazioni/MULETTO/PRENOTAZIONI/send-onesignal-push.php

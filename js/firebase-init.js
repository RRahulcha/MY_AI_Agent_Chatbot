import { initializeApp } from "https://www.gstatic.com/firebasejs/10.7.1/firebase-app.js";
import { getMessaging, getToken, onMessage } from "https://www.gstatic.com/firebasejs/10.7.1/firebase-messaging.js";

const firebaseConfig = {
  apiKey: "AIzaSyA2aUSyzl4lfRBqx0BIgDegt5-P7XXLodw",
  authDomain: "ams-notifications-d0c82.firebaseapp.com",
  projectId: "ams-notifications-d0c82",
  storageBucket: "ams-notifications-d0c82.firebasestorage.app",
  messagingSenderId: "507509976225",
  appId: "1:507509976225:web:b4ef66832e3cc75f4f94e0"
};

const app = initializeApp(firebaseConfig);
const messaging = getMessaging(app);

// Dynamic base URL derived from this module's location
const projectBaseUrl = new URL('../', import.meta.url).href;
const swUrl = new URL('firebase-messaging-sw.js', projectBaseUrl).href;
const saveTokenUrl = new URL('ajax/save_token.php', projectBaseUrl).href;

if ("serviceWorker" in navigator) {
    navigator.serviceWorker.register(swUrl)
      .then(() => console.log("✅ Firebase SW registered"))
      .catch(err => console.error("❌ SW error", err));
  }

// Ask notification permission
Notification.requestPermission().then(permission => {
  if (permission === "granted") {
    getToken(messaging, {
      vapidKey: "BBjjWZ9hq4L-cF71woo-7wHBhfQdF1KNRCunO6jtgcYTBOBrSe1z99COpDd1uexRfx4isVU9cmL19kH5ApxQOWw"

    }).then(token => {

      fetch(saveTokenUrl, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ token })
      });

    }).catch(err =>{
        console.error("❌ Token error", err);
    });
  }
});

// Receive notification while AMS is open
onMessage(messaging, payload => {
  console.log("📩 Foreground message:", payload);
  new Notification(payload.notification.title, {
    body: payload.notification.body
  });
});

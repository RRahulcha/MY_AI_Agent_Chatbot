importScripts("https://www.gstatic.com/firebasejs/10.7.1/firebase-app-compat.js");
importScripts("https://www.gstatic.com/firebasejs/10.7.1/firebase-messaging-compat.js");

firebase.initializeApp({
  apiKey: "AIzaSyA2aUSyzl4lfRBqx0BIgDegt5-P7XXLodw",
  authDomain: "ams-notifications-d0c82.firebaseapp.com",
  projectId: "ams-notifications-d0c82",
  messagingSenderId: "507509976225",
  appId: "1:507509976225:web:b4ef66832e3cc75f4f94e0"
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage(function (payload) {
  console.log("📩 Background message received:", payload);

  self.registration.showNotification(payload.notification.title, {
    body: payload.notification.body,
    icon: "/favicon.ico"
  });
});

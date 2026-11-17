/*
 * PATIENT MONITORING SYSTEM
 * Firebase background notification service worker
 */

importScripts(
  'https://www.gstatic.com/firebasejs/10.13.2/firebase-app-compat.js'
);

importScripts(
  'https://www.gstatic.com/firebasejs/10.13.2/firebase-messaging-compat.js'
);

firebase.initializeApp({
  apiKey: "AIzaSyB_k8jMEE9tT1HuTVvnpgQt6iVsHR4vjzM",
  authDomain: "patientmonitoringsystem-62f35.firebaseapp.com",
  projectId: "patientmonitoringsystem-62f35",
  storageBucket: "patientmonitoringsystem-62f35.firebasestorage.app",
  messagingSenderId: "167833950189",
  appId: "1:167833950189:web:32d5cc8ba21b67139bb9e4",
  measurementId: "G-40FY1Q1CFZ"
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage(function(payload) {

  const data = payload.data || {};

  const title =
    data.title || 'Patient Monitoring Alert';

  const options = {
    body: data.body || 'An abnormal patient reading was detected.',

    icon: '/favicon.ico',
    badge: '/favicon.ico',

    tag: data.alert_id || 'patient-monitor-alert',

    renotify: true,

    data: {
      url: '/index.php'
    }
  };

  return self.registration.showNotification(title, options);
});

self.addEventListener('notificationclick', function(event) {

  event.notification.close();

  const targetUrl = new URL(
    '/index.php',
    self.location.origin
  ).href;

  event.waitUntil(
    clients.matchAll({
      type: 'window',
      includeUncontrolled: true
    }).then(function(windowClients) {

      for (const client of windowClients) {

        if (client.url.startsWith(self.location.origin)) {
          return client.focus();
        }
      }

      return clients.openWindow(targetUrl);
    })
  );
});

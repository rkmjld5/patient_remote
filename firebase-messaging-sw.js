/*
 * PATIENT MONITORING SYSTEM
 * File: firebase-messaging-sw.js
 * Firebase Project: patientmonitoringsystem-7fb4a
 */

importScripts(
  'https://www.gstatic.com/firebasejs/10.13.2/firebase-app-compat.js'
);

importScripts(
  'https://www.gstatic.com/firebasejs/10.13.2/firebase-messaging-compat.js'
);

self.addEventListener('install', function(event) {
  event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', function(event) {
  event.waitUntil(self.clients.claim());
});

firebase.initializeApp({
  apiKey: "AIzaSyDMvg_UpBpMD2LxXaaEr4nJjqFACmDMwBQ",
  authDomain: "patientmonitoringsystem-7fb4a.firebaseapp.com",
  projectId: "patientmonitoringsystem-7fb4a",
  storageBucket: "patientmonitoringsystem-7fb4a.firebasestorage.app",
  messagingSenderId: "162369941103",
  appId: "1:162369941103:web:2104e12bc65b87cd64279a",
  measurementId: "G-87QZS9D0GT"
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
      url: data.url || '/index.php'
    }
  };

  return self.registration.showNotification(title, options);
});

self.addEventListener('notificationclick', function(event) {

  event.notification.close();

  const targetUrl = new URL(
    event.notification.data?.url || '/index.php',
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

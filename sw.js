// sw.js

self.addEventListener('message', event => {
  if (event.data && event.data.type === 'push') {
    const { title, body } = event.data;

    self.registration.showNotification(title, {
      body: body,
      tag: 'altitude-tracking', // aynı tag ile güncellenebilir
      renotify: true,
      requireInteraction: true,
      icon: 'assets/images/plane.png' // senin görselin, değiştirebilirsin
    });
  }
});

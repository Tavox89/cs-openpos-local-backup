(function($) {
    function openBillDB() {
        return new Promise((resolve, reject) => {
        const request = indexedDB.open('shared-bill-db', 1);
        request.onupgradeneeded = (e) => {
            const db = e.target.result;
            if (!db.objectStoreNames.contains('sharedData')) {
            db.createObjectStore('sharedBillData');
            }
        };
        request.onsuccess = (e) => resolve(e.target.result);
        request.onerror = (e) => reject(e.target.error);
        });
    }
  
      async function setItem(key, value) {
        const db = await openDB();
        const tx = db.transaction('sharedData', 'readwrite');
        tx.objectStore('sharedData').put(value, key);
        tx.oncomplete = () => broadcastChange(key, value);
      }
  
      async function getItem(key) {
        const db = await openDB();
        const tx = db.transaction('sharedData', 'readonly');
        const req = tx.objectStore('sharedData').get(key);
        return new Promise((resolve) => {
          req.onsuccess = () => resolve(req.result);
        });
      }
  
      // --- Broadcast setup ---
      const channel = new BroadcastChannel('shared-data');
     
      channel.onmessage = (e) => {
        const { key, value } = e.data;
        if (key === 'cart') {
            const productTpl = _.template( $( document.getElementById( 'tmpl-product-item') ).text() );
            const html = productTpl(value);
            console.log(value);
            console.log(html);
            $('#pole-container').html(html);
            console.log('Cart updated:', value);
        }
      };
})(jQuery);
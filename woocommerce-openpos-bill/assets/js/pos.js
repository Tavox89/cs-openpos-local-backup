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

  async function setBillItem(key, value) {
    const db = await openBillDB();
    const tx = db.transaction('sharedBillData', 'readwrite');
    tx.objectStore('sharedBillData').put(value, key);
    tx.oncomplete = () => broadcastChange(key, value);
  }
  const channel = new BroadcastChannel('shared-data');
  function broadcastChange(key, value) {
    channel.postMessage({ key, value });
  }

document.addEventListener("openpos.cart.update", function (e) {
    setBillItem('cart', e.detail);
  });


}(jQuery));
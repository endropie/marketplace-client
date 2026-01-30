class MCJS {

    constructor () {
        this.host = 'http://marketplace.local';
        this.storeLogged = null;
    }
    init (data = {}) {
        console.log("Marketplace Client JS Initialized");
        if (data.host) this.host = data.host;
    }

    /**
     * Registers a marketplace store.
     * Opens a new window and keeps a reference to it.
     * Checks the 'closed' property every 500 milliseconds.
     * If the user closes the window before completing registration, rejects the promise with an error message.
     * If the user successfully completes registration, resolves the promise with the registered store data.
     * @param {string} via - The marketplace via (e.g. shopee, tokopedia, lazada).
     * @returns {Promise} - A promise that resolves with the registered store data or rejects with an error message.
     */
    async register (via) {
        this.storeLogged = null;

        return new Promise((resolve, reject) => {
            // Open the new window and keep a reference to it
            const windowRegister = window.open(`${this.host}/marketplace/${via}/auth`, '_blank', 'width=870,height=520');

            if (windowRegister)
            {
                const closeCheckInterval = setInterval(() => {

                    if (windowRegister.closed) {
                        clearInterval(closeCheckInterval);

                        if (this.storeLogged === null) {
                            reject({ message: 'User closed the window before completing registration.', error: true });
                        }
                        else {
                            resolve({ data: this.storeLogged });
                        }
                        this.storeLogged = null;
                    }
                }, 1000);
            } else {
                alert('Could not open window. Please check pop-up blockers.');
            }
        });
    }

    setLogged(data) {
        this.storeLogged = data;
    }
}

window.MCJS = new MCJS;

window.MCJS.init();

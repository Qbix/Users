Q.exports(function (Users, priv) {
	/**
	 * Methods for user intents
	 * @module Users
	 * @class Users.Intents
	 */

	/**
	 * Tells server to create a Users.Intent, then immediately
     * opens corresponding URL using window.location
	 * @method start
	 * @static
     * @param {Object} capability the capability from Users.Intent.provision
     * @param {Object} [options]
     * @param {Boolean} [options.skip]
     * @param {Boolean} [options.skip.redirect]
     * @param {Boolean} [options.skip.QR]
	 */
	return function Users_Intent_start(capability, options) {
        // insert this intent
        var fields = {
            capability: capability
        }
        var action = capability.action;
        var platform = capability.platform;
        var appName = capability.appName;
        Q.req('Users/intent', function (err, response) {

        }, {
            method: 'post',
            fields: fields
        });
        var apps = Users.apps[platform] || [];
        if (!apps[appName]) {
            return false;
        }
        var url = null;
        if (Users.Intent.actions[action]) {
            url = Users.Intent.actions[action][platform];
        }
        if (!url) {
            return false;
        }
        if (!options.skip || !options.skip.QR) {
            Q.addScript("{{Q}}/js/qrcode/qrcode.js", function () {
                var element = Q.element("div");
                element.style.textAlign = "center";
                element.style.padding = "20px";

                try {
                    new QRCode(element, {
                        text: Q.url("Users/intent", fields),
                        width: 250,
                        height: 250,
                        colorDark: "#000000",
                        colorLight: "#ffffff",
                        correctLevel: QRCode.CorrectLevel.H
                    });
                } catch (e) {
                    console.error("Error rendering QRCode:", e);
                }

                Q.Dialogs.push({
                    title: "Scan this code to continue",
                    content: element
                });
            });
        }
        if (!options.skip || !options.skip.redirect) {
            url = url.interpolate(apps[appName]);
            window.location = url;
        }
    };
});
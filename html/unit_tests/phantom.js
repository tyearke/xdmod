var page = require('webpage').create();
page.open('file://' + phantom.libraryPath + '/index.html', function (status) {
    phantom.exit(1000000);
    var failures = -1;
    if (status === 'success') {
        failures = page.evaluate(function () {
            return mocha.run().failures;
        });
    }
    console.log('Javascript Unit Test Failures: ' + failures);
    phantom.exit(failures);
});

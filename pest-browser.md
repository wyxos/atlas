Browser Testing
Browser testing is an essential part of modern web development, allowing you to ensure that your application works correctly across different browsers and devices. Pest provides a simple and elegant way to write browser tests. Here is an example of how to write a browser test using Pest:

it('may welcome the user', function () {
    $page = visit('/');
 
    $page->assertSee('Welcome');
});

This is a basic example of a browser test that checks if the homepage contains the text "Welcome". However, Pest's browser testing capabilities go beyond this simple example. You can use various methods to interact with the page, such as clicking buttons, filling out forms, and navigating between pages.

Here is an example of a more complex browser test, on Laravel, that checks if a user can sign in:

it('may sign in the user', function () {
    Event::fake();
 
    User::factory()->create([ // assumes RefreshDatabase trait is used on Pest.php...
        'email' => 'nuno@laravel.com',
        'password' => 'password',
    ]);
 
    $page = visit('/')->on()->mobile()->firefox();
 
    $page->click('Sign In')
         ->assertUrlIs('/login')
         ->assertSee('Sign In to Your Account')
         ->fill('email', 'nuno@laravel.com')
         ->fill('password', 'password')
         ->click('Submit')
         ->assertSee('Dashboard');
 
    $this->assertAuthenticated();
 
    Event::assertDispatched(UserLoggedIn::class);
});

Note that, you are leveraging the full power of Laravel's testing capabilities, such as refresh database, event faking, and authentication assertions, while also actually doing browser testing.

#Getting Started
To get started with browser testing in Pest, you need to install the Pest Browser plugin. You can do this by running the following command:

composer require pestphp/pest-plugin-browser --dev
 
npm install playwright@latest
npx playwright install

Finally, add tests/Browser/Screenshots to your .gitignore file to avoid committing screenshots taken during browser tests.

#Running Browser Tests
Running browser tests is similar to running regular Pest tests:

./vendor/bin/pest

We recommend running tests in parallel using the --parallel option to speed up the execution:

./vendor/bin/pest --parallel

For debugging purposes, you can run the tests in a headed mode and pause the execution at the end of the failed test run:

./vendor/bin/pest --debug

#Navigation
The visit() method is used to navigate to a specific URL in your browser test. It provides various methods to interact with the page:

test('example', function () {
    $page = visit('/');
 
    $page->assertSee('Welcome');
});

#Using Other Browsers
By default, the visit() method uses Chrome as the browser. However, if you want to use a different browser, you can specify it using the --browser option when running the tests:

./vendor/bin/pest --browser firefox
./vendor/bin/pest --browser safari

If you wish to use a different browser by default without specifying it in the command line, you can set it in your Pest.php configuration file:

pest()->browser()->inFirefox();
pest()->browser()->inSafari();

#Using Other Devices
The visit() method uses a desktop viewport. However, you can specify a mobile viewport using the onMobile() method. For example:

$page = visit('/')->on()->mobile();

If you wish to use a specific device, you can use the on() method and chain it with the macbook14, iPhone14Pro, etc:

$page = visit('/')->on()->iPhone14Pro();

#Using Dark Mode
Pest enforces a light color scheme by default. However, you can specify a dark color scheme using the inDarkMode() method:

$page = visit('/')->inDarkMode();

#Visiting Multiple Pages
You can visit multiple pages simultaneously by passing an array of URLs to the visit() method. This is useful for testing scenarios where you need to interact with multiple pages at once:

$pages = visit(['/', '/about']);
 
$pages->assertNoSmoke()
    ->assertNoAccessibilityIssues()
    ->assertNoConsoleLogs()
    ->assertNoJavaScriptErrors();
 
[$homePage, $aboutPage] = $pages;
 
$homePage->assertSee('Welcome to our website');
$aboutPage->assertSee('About Us');

#Navigation
After visiting a page, you can navigate to other pages using the navigate() method. This method allows you to navigate to a different URL while keeping the current browser context:

$page = visit('/');
 
$page->navigate('/about')
     ->assertSee('About Us');

#Locating Elements
You can locate elements in the DOM using text or CSS selectors. Pest provides a simple syntax for locating elements:

// Clicks the first link with the text "Login"
$page->click('Login');
 
// Clicks the first element with the class "btn-primary"
$page->click('.btn-primary');
 
// Clicks the element with the data-test attribute "login"
$page->click('@login');
 
// Clicks the element with the ID "submit-button"
$page->click('#submit-button');
 
// etc...

#Configuring Timeouts
Sometimes, elements may take time to appear on the page. By default, Pest waits for 5 seconds before timing out. You can configure the default timeout for browser tests in your Pest.php configuration file:

pest()->browser()->timeout(10000);

#Configuring User Agent
By default, the User Agent will default to the Browser you're running for tests such as: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/133.0.6943.16 Safari/537.36

You may wish to override the User Agent of the browser for all of your tests, you can configure this in the Pest.php configuration file:

pest()->browser()->userAgent('CustomUserAgent');

#Geolocation
Sometimes, you need to define where the browser believes it is physically on the earth. This method takes a latitude and longitude and will set the geolocation permission in the browser and then make the coordinates available via Javascript's getCurrentPosition API:

$page = visit('/')
     ->geolocation(39.399872, -8.224454);

#Configuring Locale
You can set the locale for your test requests using the withLocale method. This is particularly useful for testing multilingual applications.

$page = visit('/')->withLocale('fr-FR');
 
$page->assertSee('Bienvenue');

#Configuring Timezone
You can set the timezone for your test requests using the withTimezone method. This is useful for testing date and time displays in different time zones.

$page = visit('/')->withTimezone('America/New_York');
 
$page->assertSee('EST');

#Configuring UserAgent
You can set the User-Agent header for your test requests using the withUserAgent method. This is useful for testing how your application responds to different types of clients, such as mobile browsers or bots.

$page = visit('/')->withUserAgent('Googlebot');
 
$page->assertSee('Welcome, bot!');

#Table of Contents
#Available Assertions
assertTitle assertTitleContains assertSee assertDontSee assertSeeIn assertDontSeeIn assertSeeAnythingIn assertSeeNothingIn assertCount assertScript assertSourceHas assertSourceMissing assertSeeLink assertDontSeeLink assertChecked assertNotChecked assertIndeterminate assertRadioSelected assertRadioNotSelected assertSelected assertNotSelected assertValue assertValueIsNot assertAttribute assertAttributeMissing assertAttributeContains assertAttributeDoesntContain assertAriaAttribute assertDataAttribute assertVisible assertPresent assertNotPresent assertMissing assertEnabled assertDisabled assertButtonEnabled assertButtonDisabled assertUrlIs assertSchemeIs assertSchemeIsNot assertHostIs assertHostIsNot assertPortIs assertPortIsNot assertPathBeginsWith assertPathEndsWith assertPathContains assertPathIs assertPathIsNot assertQueryStringHas assertQueryStringMissing assertFragmentIs assertFragmentBeginsWith assertFragmentIsNot assertNoSmoke assertNoConsoleLogs assertNoJavaScriptErrors assertNoAccessibilityIssues assertScreenshotMatches

#Element Interactions
click text attribute keys withKeyDown type typeSlowly select append clear radio check uncheck attach press pressAndWaitFor drag hover submit value withinIframe resize script content url wait waitForKey

#Debugging tests
debug screenshot screenshotElement tinker headed

#Element Assertions

#assertTitle
The assertTitle method asserts that the page title matches the given text:

$page->assertTitle('Home Page');


#assertTitleContains
The assertTitleContains method asserts that the page title contains the given text:

$page->assertTitleContains('Home');


#assertSee
The assertSee method asserts that the given text is present on the page:

$page->assertSee('Welcome to our website');


#assertDontSee
The assertDontSee method asserts that the given text is not present on the page:

$page->assertDontSee('Error occurred');


#assertSeeIn
The assertSeeIn method asserts that the given text is present within the selector:

$page->assertSeeIn('.header', 'Welcome');


#assertDontSeeIn
The assertDontSeeIn method asserts that the given text is not present within the selector:

$page->assertDontSeeIn('.error-container', 'Error occurred');


#assertSeeAnythingIn
The assertSeeAnythingIn method asserts that any text is present within the selector:

$page->assertSeeAnythingIn('.content');


#assertSeeNothingIn
The assertSeeNothingIn method asserts that no text is present within the selector:

$page->assertSeeNothingIn('.empty-container');


#assertCount
The assertCount method asserts that a given element is present a given amount of times:

$page->assertCount('.item', 5);


#assertScript
The assertScript method asserts that the given JavaScript expression evaluates to the given value:

$page->assertScript('document.title', 'Home Page');
$page->assertScript('document.querySelector(".btn").disabled', true);


#assertSourceHas
The assertSourceHas method asserts that the given source code is present on the page:

$page->assertSourceHas('<h1>Welcome</h1>');


#assertSourceMissing
The assertSourceMissing method asserts that the given source code is not present on the page:

$page->assertSourceMissing('<div class="error">');


#assertSeeLink
The assertSeeLink method asserts that the given link is present on the page:

$page->assertSeeLink('About Us');


#assertDontSeeLink
The assertDontSeeLink method asserts that the given link is not present on the page:

$page->assertDontSeeLink('Admin Panel');


#assertChecked
The assertChecked method asserts that the given checkbox is checked:

$page->assertChecked('terms');
$page->assertChecked('color', 'blue'); // For checkbox with specific value


#assertNotChecked
The assertNotChecked method asserts that the given checkbox is not checked:

$page->assertNotChecked('newsletter');
$page->assertNotChecked('color', 'red'); // For checkbox with specific value


#assertIndeterminate
The assertIndeterminate method asserts that the given checkbox is in an indeterminate state:

$page->assertIndeterminate('partial-selection');


#assertRadioSelected
The assertRadioSelected method asserts that the given radio field is selected:

$page->assertRadioSelected('size', 'large');


#assertRadioNotSelected
The assertRadioNotSelected method asserts that the given radio field is not selected:

$page->assertRadioNotSelected('size', 'small');


#assertSelected
The assertSelected method asserts that the given dropdown has the given value selected:

$page->assertSelected('country', 'US');


#assertNotSelected
The assertNotSelected method asserts that the given dropdown does not have the given value selected:

$page->assertNotSelected('country', 'UK');


#assertValue
The assertValue method asserts that the element matching the given selector has the given value:

$page->assertValue('input[name=email]', 'test@example.com');


#assertValueIsNot
The assertValueIsNot method asserts that the element matching the given selector does not have the given value:

$page->assertValueIsNot('input[name=email]', 'invalid@example.com');


#assertAttribute
The assertAttribute method asserts that the element matching the given selector has the given value in the provided attribute:

$page->assertAttribute('img', 'alt', 'Profile Picture');


#assertAttributeMissing
The assertAttributeMissing method asserts that the element matching the given selector is missing the provided attribute:

$page->assertAttributeMissing('button', 'disabled');


#assertAttributeContains
The assertAttributeContains method asserts that the element matching the given selector contains the given value in the provided attribute:

$page->assertAttributeContains('div', 'class', 'container');


#assertAttributeDoesntContain
The assertAttributeDoesntContain method asserts that the element matching the given selector does not contain the given value in the provided attribute:

$page->assertAttributeDoesntContain('div', 'class', 'hidden');


#assertAriaAttribute
The assertAriaAttribute method asserts that the element matching the given selector has the given value in the provided aria attribute:

$page->assertAriaAttribute('button', 'label', 'Close');


#assertDataAttribute
The assertDataAttribute method asserts that the element matching the given selector has the given value in the provided data attribute:

$page->assertDataAttribute('div', 'id', '123');


#assertVisible
The assertVisible method asserts that the element matching the given selector is visible:

$page->assertVisible('.alert');


#assertPresent
The assertPresent method asserts that the element matching the given selector is present in the DOM:

$page->assertPresent('form');


#assertNotPresent
The assertNotPresent method asserts that the element matching the given selector is not present in the DOM:

$page->assertNotPresent('.error-message');


#assertMissing
The assertMissing method asserts that the element matching the given selector is not visible:

$page->assertMissing('.hidden-element');


#assertEnabled
The assertEnabled method asserts that the given field is enabled:

$page->assertEnabled('email');


#assertDisabled
The assertDisabled method asserts that the given field is disabled:

$page->assertDisabled('submit');


#assertButtonEnabled
The assertButtonEnabled method asserts that the given button is enabled:

$page->assertButtonEnabled('Save');


#assertButtonDisabled
The assertButtonDisabled method asserts that the given button is disabled:

$page->assertButtonDisabled('Submit');

#URL Assertions

#assertUrlIs
The assertUrlIs method asserts that the current URL matches the given string:

$page->assertUrlIs('https://example.com/home');


#assertSchemeIs
The assertSchemeIs method asserts that the current URL scheme matches the given scheme:

$page->assertSchemeIs('https');


#assertSchemeIsNot
The assertSchemeIsNot method asserts that the current URL scheme does not match the given scheme:

$page->assertSchemeIsNot('http');


#assertHostIs
The assertHostIs method asserts that the current URL host matches the given host:

$page->assertHostIs('example.com');


#assertHostIsNot
The assertHostIsNot method asserts that the current URL host does not match the given host:

$page->assertHostIsNot('wrong-domain.com');


#assertPortIs
The assertPortIs method asserts that the current URL port matches the given port:

$page->assertPortIs('443');


#assertPortIsNot
The assertPortIsNot method asserts that the current URL port does not match the given port:

$page->assertPortIsNot('8080');


#assertPathBeginsWith
The assertPathBeginsWith method asserts that the current URL path begins with the given path:

$page->assertPathBeginsWith('/users');


#assertPathEndsWith
The assertPathEndsWith method asserts that the current URL path ends with the given path:

$page->assertPathEndsWith('/profile');


#assertPathContains
The assertPathContains method asserts that the current URL path contains the given path:

$page->assertPathContains('settings');


#assertPathIs
The assertPathIs method asserts that the current path matches the given path:

$page->assertPathIs('/dashboard');


#assertPathIsNot
The assertPathIsNot method asserts that the current path does not match the given path:

$page->assertPathIsNot('/login');


#assertQueryStringHas
The assertQueryStringHas method asserts that the given query string parameter is present and has a given value:

$page->assertQueryStringHas('page');
$page->assertQueryStringHas('page', '2');


#assertQueryStringMissing
The assertQueryStringMissing method asserts that the given query string parameter is missing:

$page->assertQueryStringMissing('page');


#assertFragmentIs
The assertFragmentIs method asserts that the URL's current hash fragment matches the given fragment:

$page->assertFragmentIs('section-2');


#assertFragmentBeginsWith
The assertFragmentBeginsWith method asserts that the URL's current hash fragment begins with the given fragment:

$page->assertFragmentBeginsWith('section');


#assertFragmentIsNot
The assertFragmentIsNot method asserts that the URL's current hash fragment does not match the given fragment:

$page->assertFragmentIsNot('wrong-section');

#Console Assertions

#assertNoSmoke
The assertNoSmoke method asserts there are no console logs or JavaScript errors on the page:

$page->assertNoSmoke();


#assertNoConsoleLogs
The assertNoConsoleLogs method asserts there are no console logs on the page:

$page->assertNoConsoleLogs();


#assertNoJavaScriptErrors
The assertNoJavaScriptErrors method asserts there are no JavaScript errors on the page:

$page->assertNoJavaScriptErrors();


#assertNoAccessibilityIssues
The assertNoAccessibilityIssues method asserts there are no "serious" accessibility issues on the page:

$page->assertNoAccessibilityIssues();

By default, the level is 1 (serious). You can change to one of the following levels:

0. Critical
1. Serious
2. Moderate
3. Minor

The level 0 (critical) only reports issues that cause severe barriers for individuals with disabilities. The organization may be subject to legal action if these issues are not addressed.
The level 1 (serious) includes all critical issues (level 0) and adds issues that significantly impact accessibility. The organization may be subject to legal action if these issues are not addressed.
The level 2 (moderate) includes all serious issues (level 1) and adds issues that moderately affect accessibility. The end-user would appreciate the fix, but it is not a barrier.
The level 3 (minor) includes all moderate issues (level 2) and adds issues that have a minor impact on accessibility. These issues are often related to best practices and do not significantly affect the user experience.
#Screenshot Assertions

#assertScreenshotMatches
The assertScreenshotMatches method asserts that the screenshot matches the expected image:

$page->assertScreenshotMatches();
$page->assertScreenshotMatches(true, true); // Full page, show diff

#Element Interactions

#click
The click method clicks the link with the given text:

$page->click('Login');

You may also pass options:

$page->click('#button', options: ['clickCount' => 2]);


#text
The text method gets the text of the element matching the given selector:

$text = $page->text('.header');


#attribute
The attribute method gets the given attribute from the element matching the given selector:

$alt = $page->attribute('img', 'alt');


#keys
The keys method sends the given keys to the element matching the given selector:

$page->keys('input[name=password]', 'secret');
$page->keys('input[name=password]', ['{Control}', 'a']); // Keyboard shortcuts


#withKeyDown
The withKeyDown method executes the given callback while a key is held down:

$page->withKeyDown('Shift', function () use ($page): void {
    $page->keys('#input', ['KeyA', 'KeyB', 'KeyC']);
}); // writes "ABC"

Note: To respect held keys like Shift, use key codes such as KeyA, KeyB, KeyC. 'a' always types a lowercase “a” and 'A' always types an uppercase “A”, regardless of modifiers.


#type
The type method types the given value in the given field:

$page->type('email', 'test@example.com');


#typeSlowly
The typeSlowly method types the given value in the given field slowly, like a user:

$page->typeSlowly('email', 'test@example.com');


#select
The select method selects the given value in the given field:

$page->select('country', 'US');
$page->select('interests', ['music', 'sports']); // Multiple select


#append
The append method types the given value in the given field without clearing it:

$page->append('description', ' Additional information.');


#clear
The clear method clears the given field:

$page->clear('search');


#radio
The radio method selects the given value of a radio button field:

$page->radio('size', 'large');


#check
The check method checks the given checkbox:

$page->check('terms');
$page->check('color', 'blue'); // For checkbox with specific value


#uncheck
The uncheck method unchecks the given checkbox:

$page->uncheck('newsletter');
$page->uncheck('color', 'red'); // For checkbox with specific value


#attach
The attach method attaches the given file to the field:

$page->attach('avatar', '/path/to/image.jpg');


#press
The press method presses the button with the given text or name:

$page->press('Submit');


#pressAndWaitFor
The pressAndWaitFor method presses the button with the given text or name and waits for a specified amount of time:

$page->pressAndWaitFor('Submit', 2); // Wait for 2 seconds


#drag
The drag method drags an element to another element using selectors:

$page->drag('#item', '#target');


#hover
The hover method hovers over the given element:

$page->hover('#item');


#submit
The submit method submits the first form found on the page:

$page->submit();


#value
The value method gets the value of the element matching the given selector:

$value = $page->value('input[name=email]');


#withinIframe
The withinIframe method allows you to interact with elements inside an iframe:

use Pest\Browser\Api\PendingAwaitablePage;
 
$page->withinIframe('.iframe-container', function (PendingAwaitablePage $page) {
    $page->type('frame-input', 'Hello iframe')
        ->click('frame-button');
});


#resize
You may use the resize method to adjust the size of the browser window:

$page->resize(1280, 720);


#script
The script method executes a script in the context of the page:

$result = $page->script('document.title');


#content
The content method gets the page's content:

$html = $page->content();


#url
The url method gets the page's URL:

$currentUrl = $page->url();


#wait
The wait method pauses for the given number of seconds:

$page->wait(2); // Wait for 2 seconds


#waitForKey
The waitForKey method opens the current page URL in the default web browser and waits for a key press:

$page->waitForKey(); // Useful for debugging

#Debugging tests
Sometimes you may want to debug your browser tests. Pest provides a convenient way to do this by using the --debug option, which makes pest to open the browser window and pause the execution of the test when it fails. You can then inspect the page and see what went wrong.

./vendor/bin/pest --debug

Optionally, you can also use the debug() method in your test. It will limit execution to this test (like using only()), pause the execution and open the browser window:

$page->debug();

You can also take a screenshot of the current page using the screenshot() method. This is useful for visual debugging:

NOTE: If you don't pass the filename, it will use the test name as the filename.

$page->screenshot();
$page->screenshot(fullPage: true);
$page->screenshot(filename: 'custom-name');

You can also take a screenshot of a specific element using the screenshotElement() method:

$page->screenshotElement('#my-element');

You can also use the tinker() method to open a Tinker session in the context of the current page. This allows you to interact with the page using PHP code:

$page->tinker();

After you can run your tests with the --headed option to open the browser window:

./vendor/bin/pest --headed

If you wish to run the tests in a headed mode by default, you can set it in your Pest.php configuration file:

pest()->browser()->headed();

#Continuous Integration
You may refer to Pest's Continuous Integration documentation for more information on how to run your browser tests in a CI environment.

However, if you are using GitHub Actions, you need to add the following steps to your workflow file:

- uses: actions/setup-node@v4
  with:
    node-version: lts/*
 
- name: Install dependencies
  run: npm ci
 
- name: Install Playwright Browsers
  run: npx playwright install --with-deps

Now, let's dive into architectural testing and how it can benefit your development process. By performing architectural testing, you can evaluate the overall design of your application and identify potential flaws before they become significant issues: Arch Testing
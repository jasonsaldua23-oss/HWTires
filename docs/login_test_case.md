# Login Test Case

## TC-LOGIN-001: Valid Front Desk Login

| Field | Details |
| --- | --- |
| Module | Authentication / User Login |
| Test Type | Functional, positive |
| Priority | Critical |
| Test URL | `http://localhost/hwtires/index.php` |
| Test Data | Login ID: `frontdesk3@hwtires.local`<br>Password: `password123` |
| Preconditions | XAMPP Apache and MySQL are running. The `hwtires` database is loaded with seeded users. The tester is signed out or using a new browser session. |
| Postconditions | The front desk user is authenticated and can access only front desk pages for the assigned branch. |

### Steps and Expected Results

| Step No. | Test Step | Expected Result |
| --- | --- | --- |
| 1 | Open `http://localhost/hwtires/index.php`. | The Highway Tires login page is displayed with the company logo, system title, Login ID field, Password field, and Sign In button. |
| 2 | Enter `frontdesk3@hwtires.local` in the Login ID field. | The Login ID field accepts the entered value. |
| 3 | Enter `password123` in the Password field. | The password is masked while typing. |
| 4 | Click the Sign In button. | The system validates the credentials. |
| 5 | Observe the page after submission. | The user is redirected to `/hwtires/front-desk/`. |
| 6 | Verify the dashboard and navigation. | The front desk dashboard is visible and displays front desk/branch-related navigation only. |

### Expected Overall Result

The user logs in successfully using valid front desk credentials and is redirected to the front desk dashboard without seeing an error message.

### Actual Result

To be filled during execution.

### Status

Not Run

## Negative Check: Invalid Password

| Step No. | Test Step | Expected Result |
| --- | --- | --- |
| 1 | Open `http://localhost/hwtires/index.php` in a signed-out browser session. | The login page is displayed. |
| 2 | Enter `frontdesk3@hwtires.local` as the Login ID. | The Login ID field accepts the value. |
| 3 | Enter an incorrect password, such as `wrong-password`. | The password field accepts and masks the value. |
| 4 | Click the Sign In button. | The message `Invalid login ID or password.` is displayed. The user remains on the login page and no protected dashboard is opened. |

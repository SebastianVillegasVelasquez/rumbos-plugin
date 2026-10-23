@format @format_rumbos @javascript
Feature: The size of the Rumbos area
  In order to give the map the room it needs on every screen
  As a teacher or an administrator
  I need the height to follow the course options, the site defaults and the site limits

  Background:
    Given the following config values are set as admin:
      | config    | value                  | plugin        |
      | enabled   | 1                      | format_rumbos |
      | appurl    | https://rumbos.invalid | format_rumbos |
      | timeoutms | 120000                 | format_rumbos |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |

  Scenario: A course with a fixed height gets exactly that room before the app loads
    Given the following "courses" exist:
      | fullname | shortname | format | rumbos_heightmode | rumbos_heightvalue |
      | Course 1 | C1        | rumbos | fixed             | 500                |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    When I am on the "Course 1" course page logged in as student1
    Then "[data-region='rumbos-container'][data-heightmode='fixed']" "css_element" should exist
    And the Rumbos area should be 500 pixels tall

  Scenario: A course with a percentage of the screen height follows the screen
    Given the following "courses" exist:
      | fullname | shortname | format | rumbos_heightmode | rumbos_heightvalue |
      | Course 1 | C1        | rumbos | viewport          | 50                 |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And I change window size to "large"
    When I am on the "Course 1" course page logged in as student1
    Then the Rumbos area should be 50 percent of the screen height

  Scenario: A course that chooses nothing gets the site default
    Given the following config values are set as admin:
      | config      | value | plugin        |
      | heightmode  | fixed | format_rumbos |
      | heightvalue | 640   | format_rumbos |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | rumbos |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    When I am on the "Course 1" course page logged in as student1
    Then the Rumbos area should be 640 pixels tall

  Scenario: The course option wins over the site default
    Given the following config values are set as admin:
      | config      | value | plugin        |
      | heightmode  | fixed | format_rumbos |
      | heightvalue | 640   | format_rumbos |
    And the following "courses" exist:
      | fullname | shortname | format | rumbos_heightmode | rumbos_heightvalue |
      | Course 1 | C1        | rumbos | fixed             | 420                |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    When I am on the "Course 1" course page logged in as student1
    Then the Rumbos area should be 420 pixels tall

  Scenario: No course can exceed the site maximum
    Given the following config values are set as admin:
      | config    | value | plugin        |
      | maxheight | 500   | format_rumbos |
    And the following "courses" exist:
      | fullname | shortname | format | rumbos_heightmode | rumbos_heightvalue |
      | Course 1 | C1        | rumbos | fixed             | 1200               |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    When I am on the "Course 1" course page logged in as student1
    Then the Rumbos area should be 500 pixels tall

  Scenario: No course can go under the site minimum
    Given the following config values are set as admin:
      | config    | value | plugin        |
      | minheight | 450   | format_rumbos |
    And the following "courses" exist:
      | fullname | shortname | format | rumbos_heightmode | rumbos_heightvalue |
      | Course 1 | C1        | rumbos | fixed             | 400                |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    When I am on the "Course 1" course page logged in as student1
    Then the Rumbos area should be 450 pixels tall

  Scenario: Small screens use their own percentage of the screen height
    Given the following "courses" exist:
      | fullname | shortname | format | rumbos_heightmode | rumbos_heightvalue | rumbos_mobileheight |
      | Course 1 | C1        | rumbos | fixed             | 900                | 60                  |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And I change window size to "mobile"
    When I am on the "Course 1" course page logged in as student1
    Then the Rumbos area should be 60 percent of the screen height

  Scenario: A teacher sets the height in the course settings and the page follows
    Given the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | rumbos |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And I am on the "Course 1" course page logged in as teacher1
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    When I set the following fields to these values:
      | Height of the Rumbos area | Fixed height in pixels |
      | rumbos_heightvalue        | 520                    |
    And I press "Save and display"
    Then the Rumbos area should be 520 pixels tall

  Scenario: The course settings refuse a height outside the range of the mode
    Given the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | rumbos |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And I am on the "Course 1" course page logged in as teacher1
    And I navigate to "Settings" in current page administration
    And I expand all fieldsets
    When I set the following fields to these values:
      | Height of the Rumbos area | Fixed height in pixels |
      | rumbos_heightvalue        | 100                    |
    And I press "Save and display"
    Then I should see "Enter a height in pixels from 360 to 1600"

  Scenario: In automatic mode the height follows the proportions the app reports
    Given the following "courses" exist:
      | fullname | shortname | format | rumbos_heightmode |
      | Course 1 | C1        | rumbos | auto              |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the Rumbos signing keys are generated afresh
    And I am on the "Course 1" course page logged in as student1
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    When the Rumbos app prefers an aspect ratio of 1.5
    Then the Rumbos area should follow an aspect ratio of 1.5
    And the Rumbos app prefers an aspect ratio of 2.5
    And the Rumbos area should follow an aspect ratio of 2.5

  Scenario: In automatic mode the site limits bound the height
    Given the following config values are set as admin:
      | config    | value | plugin        |
      | maxheight | 450   | format_rumbos |
    And the following "courses" exist:
      | fullname | shortname | format | rumbos_heightmode |
      | Course 1 | C1        | rumbos | auto              |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the Rumbos signing keys are generated afresh
    And I am on the "Course 1" course page logged in as student1
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    When the Rumbos app prefers an aspect ratio of 0.5
    Then the Rumbos area should be 450 pixels tall

  Scenario: A mode other than automatic ignores the proportions the app reports
    Given the following "courses" exist:
      | fullname | shortname | format | rumbos_heightmode | rumbos_heightvalue |
      | Course 1 | C1        | rumbos | fixed             | 600                |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the Rumbos signing keys are generated afresh
    And I am on the "Course 1" course page logged in as student1
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    When the Rumbos app prefers an aspect ratio of 2
    Then the Rumbos area should be 600 pixels tall

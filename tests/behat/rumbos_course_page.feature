@format @format_rumbos @javascript
Feature: The Rumbos course page
  In order to use Rumbos without ever losing access to my activities
  As a student or a teacher
  I need the Rumbos container, a way to reach the standard list, and a fallback when the app does not answer

  Background:
    Given the following config values are set as admin:
      | config  | value                  | plugin        |
      | enabled | 1                      | format_rumbos |
      | appurl  | https://rumbos.invalid | format_rumbos |
    And the following "courses" exist:
      | fullname | shortname | format |
      | Course 1 | C1        | rumbos |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "activities" exist:
      | activity | name   | course | idnumber | section |
      | page     | Page 1 | C1     | page1    | 1       |

  Scenario: A teacher sees the Rumbos container and the core editor in edit mode
    Given I am on the "Course 1" course page logged in as teacher1
    And I turn editing mode on
    Then "[data-region='rumbos-container']" "css_element" should exist
    And "iframe[data-region='rumbos-frame']" "css_element" should exist
    And "[data-for='course_sectionlist']" "css_element" should exist
    And I should see "Page 1"
    And I should not see "View the activity list"

  Scenario: A teacher with editing off gets the map as students do and a note to turn editing on
    Given I am on the "Course 1" course page logged in as teacher1
    Then "iframe[data-region='rumbos-frame']" "css_element" should exist
    And "details#format-rumbos-list summary" "css_element" should exist
    And I should see "Turn editing on to modify the map."

  Scenario: A student does not get the note about editing
    Given I am on the "Course 1" course page logged in as student1
    Then I should not see "Turn editing on to modify the map."

  Scenario: A student sees the container and the accessible control to the activity list
    Given I am on the "Course 1" course page logged in as student1
    Then "[data-region='rumbos-container']" "css_element" should exist
    And "iframe[data-region='rumbos-frame']" "css_element" should exist
    And "details#format-rumbos-list summary" "css_element" should exist
    And "a[href='#format-rumbos-list']" "css_element" should exist

  Scenario: A student falls back to the standard content when the app does not answer
    Given I am on the "Course 1" course page logged in as student1
    When I wait until "[data-region='rumbos-container'][data-state='degraded']" "css_element" exists
    Then "iframe[data-region='rumbos-frame']" "css_element" should not be visible
    And I should see "Page 1"
    And I should not see "Rumbos did not respond in time"

  Scenario: A teacher in editing mode is told why the app was replaced by the standard content
    Given I am on the "Course 1" course page logged in as teacher1
    And I turn editing mode on
    When I wait until "[data-region='rumbos-container'][data-state='degraded']" "css_element" exists
    Then I should see "Rumbos did not respond in time"
    And I should see "Page 1"

  Scenario: With Rumbos disabled only the standard content is shown
    Given the following config values are set as admin:
      | config  | value | plugin        |
      | enabled | 0     | format_rumbos |
    When I am on the "Course 1" course page logged in as student1
    Then "[data-region='rumbos-container']" "css_element" should not exist
    And "iframe[data-region='rumbos-frame']" "css_element" should not exist
    And I should see "Page 1"

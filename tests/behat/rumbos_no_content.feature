@format @format_rumbos @javascript
Feature: The Rumbos app has nothing to show
  In order to never be left with an empty course page
  As a student or a teacher
  I need the standard course content when the app says it has no map to show

  Background:
    Given the following config values are set as admin:
      | config    | value                  | plugin        |
      | enabled   | 1                      | format_rumbos |
      | appurl    | https://rumbos.invalid | format_rumbos |
      | timeoutms | 120000                 | format_rumbos |
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
    And the Rumbos signing keys are generated afresh

  Scenario: A student silently gets the standard content when no map was saved
    Given I am on the "Course 1" course page logged in as student1
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    When the Rumbos app reports that it has no content because "no_saved_map"
    Then the Rumbos host should have fallen back because "no_saved_map"
    And "iframe[data-region='rumbos-frame']" "css_element" should not be visible
    And I should see "Page 1"
    And I should not see "There is no saved map yet"
    And "[data-region='rumbos-notice']" "css_element" should not be visible

  Scenario: A student silently gets the standard content when the map is empty
    Given I am on the "Course 1" course page logged in as student1
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    When the Rumbos app reports that it has no content because "empty_map"
    Then the Rumbos host should have fallen back because "empty_map"
    And I should see "Page 1"
    And I should not see "The map has no bubbles yet"

  Scenario: A teacher is told that no map was saved and keeps the standard content
    Given I am on the "Course 1" course page logged in as teacher1
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    When the Rumbos app reports that it has no content because "no_saved_map"
    Then I should see "There is no saved map yet. Turn editing on for the course and press Save."
    And I should see "Page 1"
    And "iframe[data-region='rumbos-frame']" "css_element" should not be visible

  Scenario: A teacher is told that the map has no bubbles
    Given I am on the "Course 1" course page logged in as teacher1
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    When the Rumbos app reports that it has no content because "empty_map"
    Then I should see "The map has no bubbles yet."
    And I should see "Page 1"

  Scenario: A repeated message does not change what the user sees
    Given I am on the "Course 1" course page logged in as teacher1
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    When the Rumbos app reports that it has no content because "no_saved_map"
    And the Rumbos app reports that it has no content because "empty_map"
    Then the Rumbos host should have fallen back because "no_saved_map"
    And I should see "There is no saved map yet."
    And I should not see "The map has no bubbles yet."

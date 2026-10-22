@format @format_rumbos @javascript
Feature: Configuring the completion of an activity from the Rumbos map editor
  In order to fix an activity that never completes
  As a teacher editing the map
  I need to jump to the activity's settings in one click, and nobody else should be able to

  Background:
    Given the following config values are set as admin:
      | config    | value                  | plugin        |
      | enabled   | 1                      | format_rumbos |
      | appurl    | https://rumbos.invalid | format_rumbos |
      | timeoutms | 120000                 | format_rumbos |
    And the following "courses" exist:
      | fullname | shortname | format | enablecompletion |
      | Course 1 | C1        | rumbos | 1                |
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

  Scenario: A teacher editing the map is sent to the activity settings in a new tab
    Given I am on the "Course 1" course page logged in as teacher1
    And I turn editing mode on
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    When the Rumbos app asks to open the settings of the activity "Page 1"
    Then a new tab should have been requested for the settings of the activity "Page 1"

  Scenario: An activity the host does not know is ignored
    Given I am on the "Course 1" course page logged in as teacher1
    And I turn editing mode on
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    When the Rumbos app asks to open the settings of an activity that does not exist
    Then no new tab should have been requested

  Scenario: Nothing is opened when the teacher is not editing the map
    Given I am on the "Course 1" course page logged in as teacher1
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    When the Rumbos app asks to open the settings of the activity "Page 1"
    Then no new tab should have been requested

  Scenario: Nothing is opened for a student
    Given I am on the "Course 1" course page logged in as student1
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    When the Rumbos app asks to open the settings of the activity "Page 1"
    Then no new tab should have been requested

  Scenario: The address lands on the settings page with the completion section
    Given I am on the "Page 1" "page activity editing" page logged in as teacher1
    Then I should see "Completion conditions"

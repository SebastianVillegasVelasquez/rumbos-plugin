@format @format_rumbos @javascript
Feature: Leaving a SCORM activity from the popup
  In order to keep what a SCORM package reported and to see one slim header
  As a student
  I need the popup to show no second exit row and to save the package's values before it closes

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
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the following "activities" exist:
      | activity | name      | course | idnumber | section | packagefilepath                                  | skipview |
      | scorm    | Package 1 | C1     | scorm1   | 1       | mod/scorm/tests/packages/singlescobasic.zip      | 2        |
    And the Rumbos signing keys are generated afresh
    And I am on the "Course 1" course page logged in as student1
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready
    And the Rumbos app asks to open the SCORM activity "Package 1"
    And I watch the SCORM player in the Rumbos popup

  Scenario: The player's own exit row is not shown in the popup
    When I switch to "rumbos-popup" iframe
    Then I should not see "Exit activity"
    And I switch to the main frame
    And I should see "Back to the map" in the ".format-rumbos-popup" "css_element"

  Scenario: Back to the map saves what the package reported before the popup closes
    When I click on "Back to the map" "button" in the ".format-rumbos-popup" "css_element"
    Then the Rumbos popup should close
    And the SCORM player in the Rumbos popup should have been told to commit
    And the Rumbos app should have been sent "rumbos:host-state, rumbos:host-overlay:false" last

  Scenario: Escape saves what the package reported before the popup closes
    When I press the escape key
    Then the Rumbos popup should close
    And the SCORM player in the Rumbos popup should have been told to commit

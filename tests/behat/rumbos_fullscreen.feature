@format @format_rumbos @javascript
Feature: Full screen mode of the Rumbos map
  In order to use the whole screen for the map without getting stuck in it
  As a student
  I need an exit control that the host owns, in a fixed place, above the app

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
      | student1 | Student   | One      | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role    |
      | student1 | C1     | student |
    And the Rumbos signing keys are generated afresh
    And I am on the "Course 1" course page logged in as student1
    And the Rumbos app announces that it is ready
    And the Rumbos host should be ready

  Scenario: The exit control is not there until the map is full screen
    Then "[data-action='rumbos-exit-fullscreen']" "css_element" should not be visible

  Scenario: The host owns the top-right corner while the map is full screen
    When the Rumbos app asks for full screen on
    Then the Rumbos exit control should own the top-right corner of the screen
    And the Rumbos app should have been sent "rumbos:host-fullscreen:true" last

  Scenario: The exit control leaves full screen and tells the app
    Given the Rumbos app asks for full screen on
    When I click on "Exit full screen" "button"
    Then "[data-action='rumbos-exit-fullscreen']" "css_element" should not be visible
    And the Rumbos app should have been sent "rumbos:host-fullscreen:true, rumbos:host-fullscreen:false" last

  Scenario: The app asking to leave full screen is answered with the real state
    Given the Rumbos app asks for full screen on
    When the Rumbos app asks for full screen off
    Then "[data-action='rumbos-exit-fullscreen']" "css_element" should not be visible
    And the Rumbos app should have been sent "rumbos:host-fullscreen:true, rumbos:host-fullscreen:false" last

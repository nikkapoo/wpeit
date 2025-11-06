=== Event Flyer Generator & Importer ===
Contributors: liibooz
Tags: events, import, ical, eventon, the events calendar, modern events calendar, automate, featured image, image generator
Requires at least: 5.5
Tested up to: 6.5
Stable tag: 2.2.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The ultimate automation tool for event websites. Import events from any iCal/.ics feed and automatically generate stunning, branded flyer images for each one.

== Description ==

Stop wasting time manually creating events and designing flyers. The Event Flyer Generator & Importer is the ultimate automation tool for any event-based WordPress site.

This plugin connects to any iCal/.ics feed (like a public Google Calendar) and automatically imports new events on a schedule. But it doesn't stop there. For every new event that doesn't have a featured image, it automatically generates a beautiful, custom-branded flyer based on your predefined templates and settings.

It's designed to be a "set it and forget it" solution for busy site owners who want a professional, dynamic, and effortlessly updated event listing.

Key Features:

Automatic iCal Importing: Pulls events from multiple iCal/.ics feeds on a twice-daily schedule.

Multi-Calendar Support: Automatically detects and integrates with EventON, The Events Calendar, and Modern Events Calendar.

Automatic Flyer Generation: Creates a unique featured image for any new event that doesn't have one, using your custom templates, colors, and logos.

Intelligent Location & Organizer Mapping: Automatically assigns events to the correct location and organizer, even with messy data from the feed.

Powerful Title Cleanup: A dedicated admin page to define blacklist words, find-and-replace rules, and other logic to automatically sanitize event titles upon import.

Bulk Management Tools: Robust admin panels for managing locations, organizers, and running bulk cleanup or image generation tasks on existing events.

SEO Optimized: Creates clean, sortable event data and provides tools to manage permalinks and redirect redundant organizer pages for better SEO.

This plugin is perfect for destination guides, nightlife portals, community calendars, and any website that needs to display a high volume of events with minimal manual effort.

== Installation ==

Upload the efg-plugin folder to the /wp-content/plugins/ directory.

Important: Open a command line interface, navigate to the plugin's directory (/wp-content/plugins/kph-ical-importer/), and run the command composer install to download the required iCal parsing library.

Activate the plugin through the 'Plugins' menu in WordPress.

Navigate to Settings > Flyer & Import Settings to add your iCal feed URL(s).

Navigate to Events > Event Header Generator to set up your default flyer template and colors.

(Optional) Navigate to Events > Event Cleanup to define your title sanitization rules.

That's it! The plugin will now automatically import events and generate flyers on its schedule.

== Frequently Asked Questions ==

Does this work with my calendar plugin?
This plugin automatically detects and works with EventON, The Events Calendar, and Modern Events Calendar. One of these must be active for the plugin to function.

Where do I get an iCal/.ics feed URL?
You can get one from a public Google Calendar, or export one from many other event platforms and services.

How do I set up the flyer templates?
Go to Events > Event Header Generator. Here you can set a default background image (1080x1350px recommended) and default colors. You can also override these settings for specific locations by editing the location term under Events > Locations.

The generated images have no text on them.
This almost always means the required font file is missing. Please create an assets folder inside the main plugin folder (/kph-ical-importer/) and upload a font file named exactly font.ttf into it. We recommend "Anton Regular" or "Roboto Regular" from Google Fonts.

== Changelog ==

= 2.2.0 =

Feature: Added a centralized Permalink Settings section to the main settings page.

Fix: Automatically flushes rewrite rules on settings save.

= 2.1.0 =

Branding: Renamed plugin to "Event Flyer Generator & Importer" to better reflect its key feature.

UI: Updated admin menu and page titles.

= 2.0.0 =

Major Feature: Added a Calendar Adapter to provide automatic support for The Events Calendar and Modern Events Calendar, in addition to EventON.

Security: Hardened all form and URL processing with enhanced sanitization and nonce verification.

= 1.5.x =

Feature: Added robust event title cleanup and filtering tools.

Feature: Added organizer cleanup and automatic redirect tools.

Feature: Added sortable "Import Date" column to the events list.

Fix: Resolved numerous bugs related to image generation, including PNG transparency, text positioning, and font rendering.

Fix: Corrected timezone handling for all imported events.

Fix: Implemented robust de-duplication logic.

= 1.0.0 =

Initial release.
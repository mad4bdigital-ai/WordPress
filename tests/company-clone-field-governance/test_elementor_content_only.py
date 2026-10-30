#!/usr/bin/env python3
"""Regression tests for the central Elementor demo design invariants."""
import copy
import unittest

from scripts.elementor_demo_content_guard import ContentOnlyViolation, validate_content_only


def original():
    return [
        {
            "id": "parent1",
            "elType": "container",
            "settings": {"flex_direction": "column", "padding": {"top": "8", "bottom": "8"}},
            "elements": [
                {"id": "title1", "elType": "widget", "widgetType": "heading", "settings": {"title": "Original title", "align": "center"}, "elements": []},
                {"id": "copy1", "elType": "widget", "widgetType": "text-editor", "settings": {"editor": "<p class=\"intro\">Original <strong>message</strong></p>", "__dynamic__": {"editor": "source"}}, "elements": []},
                {"id": "gallery1", "elType": "widget", "widgetType": "uael-image-gallery", "settings": {"gallery_style": "carousel", "__dynamic__": {"wp_gallery": "tenant-owned"}}, "elements": []},
                {"id": "tabs1", "elType": "widget", "widgetType": "nested-tabs", "settings": {"tabs": [{"tab_title": "Source verified", "_id": "tab1"}]}, "elements": [
                    {"id": "template1", "elType": "widget", "widgetType": "template", "settings": {"template_id": "45494"}, "elements": []}
                ]},
                {"id": "form1", "elType": "widget", "widgetType": "fluent-form-widget", "settings": {"form_list": "2"}, "elements": []},
                {"id": "cta1", "elType": "widget", "widgetType": "button", "settings": {"text": "Explore", "link": {"url": "/tours-and-activities/"}}, "elements": []}
            ]
        }
    ]


class ElementorDemoCopyOnlyTest(unittest.TestCase):
    def setUp(self):
        self.before = original()
        self.after = copy.deepcopy(self.before)

    def test_allows_existing_heading_and_editor_text_only(self):
        self.after[0]["elements"][0]["settings"]["title"] = "Wo Vacation inspiration"
        self.after[0]["elements"][1]["settings"]["editor"] = "<p class=\"intro\">Travel <strong>inspiration</strong></p>"
        self.assertEqual(validate_content_only(self.before, self.after), 2)

    def test_allows_existing_button_label_only(self):
        self.after[0]["elements"][-1]["settings"]["text"] = "Discover Demo Tours"
        self.assertEqual(validate_content_only(self.before, self.after), 1)

    def test_rejects_gallery_replaced_by_text_editor(self):
        widget = self.after[0]["elements"][2]
        widget["widgetType"] = "text-editor"
        widget["settings"] = {"editor": "Inspiration"}
        self.assertRaises(ContentOnlyViolation, validate_content_only, self.before, self.after)

    def test_rejects_tabs_replaced_by_text_editor(self):
        widget = self.after[0]["elements"][3]
        widget["widgetType"] = "text-editor"
        widget["elements"] = []
        self.assertRaises(ContentOnlyViolation, validate_content_only, self.before, self.after)

    def test_rejects_form_replaced_by_button(self):
        self.after[0]["elements"][4]["widgetType"] = "button"
        self.assertRaises(ContentOnlyViolation, validate_content_only, self.before, self.after)

    def test_rejects_extra_translation_home_section(self):
        self.after.insert(0, copy.deepcopy(self.after[0]))
        self.assertRaises(ContentOnlyViolation, validate_content_only, self.before, self.after)

    def test_rejects_widget_deleted_or_reordered(self):
        self.after[0]["elements"].pop(2)
        self.assertRaises(ContentOnlyViolation, validate_content_only, self.before, self.after)
        self.after = copy.deepcopy(self.before)
        self.after[0]["elements"][0], self.after[0]["elements"][1] = (
            self.after[0]["elements"][1], self.after[0]["elements"][0]
        )
        self.assertRaises(ContentOnlyViolation, validate_content_only, self.before, self.after)

    def test_rejects_any_layout_css_or_responsive_change(self):
        self.after[0]["settings"]["padding"]["top"] = "9"
        self.assertRaises(ContentOnlyViolation, validate_content_only, self.before, self.after)
        self.after = copy.deepcopy(self.before)
        self.after[0]["elements"][0]["settings"]["align"] = "left"
        self.assertRaises(ContentOnlyViolation, validate_content_only, self.before, self.after)

    def test_rejects_widget_dynamic_data_or_template_change(self):
        self.after[0]["elements"][1]["settings"]["__dynamic__"]["editor"] = "unsafe-other-company"
        self.assertRaises(ContentOnlyViolation, validate_content_only, self.before, self.after)
        self.after = copy.deepcopy(self.before)
        self.after[0]["elements"][3]["elements"][0]["settings"]["template_id"] = "99999"
        self.assertRaises(ContentOnlyViolation, validate_content_only, self.before, self.after)
        self.after = copy.deepcopy(self.before)
        self.after[0]["elements"][2]["settings"]["__dynamic__"]["wp_gallery"] = "some-other-tenant"
        self.assertRaises(ContentOnlyViolation, validate_content_only, self.before, self.after)

    def test_rejects_inline_html_markup_or_class_change(self):
        self.after[0]["elements"][1]["settings"]["editor"] = "<p class=\"different\">Travel <strong>inspiration</strong></p>"
        self.assertRaises(ContentOnlyViolation, validate_content_only, self.before, self.after)
        self.after = copy.deepcopy(self.before)
        self.after[0]["elements"][1]["settings"]["editor"] = "<p class=\"intro\">Travel <em>inspiration</em></p>"
        self.assertRaises(ContentOnlyViolation, validate_content_only, self.before, self.after)

    def test_rejects_noop_edit(self):
        self.assertRaises(ContentOnlyViolation, validate_content_only, self.before, self.after)


if __name__ == "__main__":
    unittest.main()

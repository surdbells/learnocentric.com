import { ComponentFixture, TestBed } from '@angular/core/testing';

import { PackageAssignment } from './package-assignment';

describe('PackageAssignment', () => {
  let component: PackageAssignment;
  let fixture: ComponentFixture<PackageAssignment>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [PackageAssignment]
    })
    .compileComponents();

    fixture = TestBed.createComponent(PackageAssignment);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
